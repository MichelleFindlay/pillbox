<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();

$to = parse_date(get('to')) ?? $today;
$from = parse_date(get('from')) ?? $to->modify('-6 days');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if ($from < $to->modify('-366 days')) {
    $from = $to->modify('-366 days');
}
$medFilter = (int) get('med') ?: null;
$show = in_array(get('show'), ['meds', 'care'], true) ? get('show') : '';

$utc = new DateTimeZone('UTC');
$slots = $show === 'care' ? [] : dose_slots($user, $from, $to, false, $medFilter);
$prn = $show === 'care' ? [] : prn_logs($uid, $from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'));
if ($medFilter) {
    $prn = array_filter($prn, fn ($l) => (int) $l['medication_id'] === $medFilter);
}
$adherence = adherence($slots);
$meds = user_medications($uid, false);

// Care routines (and Mind habits), so past days can be ticked off too. Not when looking at one medicine.
$careTasks = [];
if ($show !== 'meds' && !$medFilter && share_can('care', 'view')) {
    $areas = routine_areas($user);
    $careTasks = array_values(array_filter(care_tasks($uid), fn ($t) => in_array($t['area'], $areas, true) && share_area_allowed($t['area'])
        && ($t['area'] !== 'mind' || mind_unlocked($user))));
}
// Mind's self-care checklist: only when Mind is on, shared and unlocked (it can have a PIN).
$mindOn = $show !== 'meds' && !$medFilter && mind_enabled($user);
$mindLocked = $mindOn && !mind_unlocked($user);
$selfcareItems = $mindOn && !$mindLocked ? selfcare_items($user) : [];
$selfcareDone = []; // date => [item => true]
if ($selfcareItems) {
    foreach (q('SELECT log_date, item FROM mind_selfcare WHERE user_id = ? AND log_date BETWEEN ? AND ?', [$uid, $from->format('Y-m-d'), $to->format('Y-m-d')]) as $r) {
        $selfcareDone[$r['log_date']][$r['item']] = true;
    }
}
$selfcareTotals = []; // item => days done
$careLogs = $careTasks ? care_logs($uid, $from->format('Y-m-d'), $to->format('Y-m-d')) : [];
$careTotals = []; // task id => [done, due]

// Group slots, PRN doses and care tasks by local day, newest first.
$days = [];
for ($d = min($to, $today); $selfcareItems && $d >= $from; $d = $d->modify('-1 day')) {
    $ymd = $d->format('Y-m-d');
    // Today's checklist, plus anything ticked that day that has since been taken off it.
    $days[$ymd]['selfcare'] = array_values(array_unique([...$selfcareItems, ...array_keys($selfcareDone[$ymd] ?? [])]));
    foreach (array_keys($selfcareDone[$ymd] ?? []) as $item) {
        $selfcareTotals[$item] = ($selfcareTotals[$item] ?? 0) + 1;
    }
}
$selfcareDays = $selfcareItems ? (int) min($to, $today)->diff($from)->days + 1 : 0;
for ($d = min($to, $today); $careTasks && $d >= $from; $d = $d->modify('-1 day')) {
    foreach ($careTasks as $t) {
        if (!care_due($t, $d)) {
            continue;
        }
        $days[$d->format('Y-m-d')]['care'][] = $t;
        foreach (array_keys(care_times($t)) as $slot) {
            $careTotals[$t['id']][1] = ($careTotals[$t['id']][1] ?? 0) + 1;
            $careTotals[$t['id']][0] = ($careTotals[$t['id']][0] ?? 0) + (care_done($careLogs, $t, $d, $slot) ? 1 : 0);
        }
    }
}
foreach ($slots as $slot) {
    $days[$slot['local']->format('Y-m-d')]['slots'][] = $slot;
}
foreach ($prn as $log) {
    $days[local_time($log['taken_at'], 'Y-m-d')]['prn'][] = $log;
}
krsort($days);

$return = 'history.php?' . http_build_query(array_filter(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'med' => $medFilter, 'show' => $show]));

render_header('History', 'history');
?>
<div class="page-head">
    <h1>History <?= help_link('reports', 'history', 'history') ?></h1>
</div>

<form method="get" class="card filters">
    <label>From <input type="date" name="from" value="<?= h($from->format('Y-m-d')) ?>"></label>
    <label>To <input type="date" name="to" value="<?= h($to->format('Y-m-d')) ?>"></label>
    <label>Show
        <select name="show">
            <option value="">Medicines and Care</option>
            <option value="meds"<?= selected($show === 'meds') ?>>Medicines</option>
            <option value="care"<?= selected($show === 'care') ?>>Care</option>
        </select>
    </label>
    <label>Medication
        <select name="med">
            <option value="">All</option>
            <?php foreach ($meds as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($medFilter === (int) $m['id']) ?>><?= h($m['name']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <button class="btn" type="submit">Show</button>
</form>

<?php if ($adherence): ?>
<section class="card">
    <h2>Adherence</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Medication</th><th class="num">Taken</th><th class="num">Skipped</th><th class="num">Missed</th><th>Adherence</th></tr></thead>
        <tbody>
        <?php foreach ($adherence as $a): ?>
            <tr>
                <td><?= med_dot($a['colour']) ?> <?= h($a['name']) ?> <span class="muted"><?= h($a['strength']) ?></span></td>
                <td class="num"><?= $a['taken'] ?></td>
                <td class="num"><?= $a['skipped'] ?></td>
                <td class="num"><?= $a['missed'] ?></td>
                <td><?php if ($a['pct'] !== null): ?>
                    <span class="meter"><meter min="0" max="100" low="80" high="95" optimum="100" value="<?= $a['pct'] ?>"></meter> <?= $a['pct'] ?>%</span>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<?php if ($mindLocked): ?>
<p class="flash flash-info notice-row"><span>Mind is locked, so self-care and Mind habits aren't shown.</span>
    <a class="btn btn-small" href="<?= h(url('mind_unlock.php?return=' . urlencode($return))) ?>">Unlock</a></p>
<?php endif; ?>

<?php if ($careTotals || $selfcareItems): ?>
<section class="card">
    <h2><?= $careTotals && $selfcareItems ? 'Care routines and self-care' : ($careTotals ? 'Care routines' : 'Self-care') ?></h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Routine</th><th class="num">Done</th><th class="num">Due</th><th>Rate</th></tr></thead>
        <tbody>
        <?php foreach ($careTasks as $t): if (!isset($careTotals[$t['id']])) { continue; } [$done, $due] = $careTotals[$t['id']]; $pct = $due ? (int) round(100 * $done / $due) : null; ?>
            <tr>
                <td><?= CARE_AREAS[$t['area']]['icon'] ?? '' ?> <?= h($t['label']) ?> <span class="muted small"><?= h(CARE_AREAS[$t['area']]['short'] ?? '') ?></span></td>
                <td class="num"><?= $done ?></td>
                <td class="num"><?= $due ?></td>
                <td><?php if ($pct !== null): ?><span class="meter"><meter min="0" max="100" low="50" high="80" optimum="100" value="<?= $pct ?>"></meter> <?= $pct ?>%</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach (array_unique([...$selfcareItems, ...array_keys($selfcareTotals)]) as $item): $done = $selfcareTotals[$item] ?? 0; $pct = (int) round(100 * $done / max(1, $selfcareDays)); ?>
            <tr>
                <td>&#x1F331; <?= h($item) ?> <span class="muted small">Self-care</span></td>
                <td class="num"><?= $done ?></td>
                <td class="num"><?= $selfcareDays ?> days</td>
                <td><span class="meter"><meter min="0" max="100" low="50" high="80" optimum="100" value="<?= $pct ?>"></meter> <?= $pct ?>%</span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (share_can('care', 'edit') || share_can('mind', 'edit')): ?><p class="muted small">Forgot to tick something off? Tick it on the day below.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$days): ?>
<section class="card"><p class="muted">Nothing in this period.</p></section>
<?php endif; ?>

<?php foreach ($days as $ymd => $day): ?>
<section class="card" id="day-<?= h($ymd) ?>">
    <h2><?= h(parse_date($ymd)?->format('l j F Y') ?? $ymd) ?></h2>
    <?php if (!empty($day['slots'])): ?>
    <ul class="dose-list">
        <?php foreach ($day['slots'] as $slot) { render_dose_item($slot, $return); } ?>
    </ul>
    <?php endif; ?>
    <?php if (!empty($day['prn'])): ?>
    <h3>As needed</h3>
    <ul class="plain">
        <?php foreach ($day['prn'] as $log): ?>
        <li class="logline">
            <span><?= med_dot($log['colour']) ?> <?= h(local_time($log['taken_at'], 'H:i')) ?> · <?= h($log['name']) ?> · <?= h(dose_label($log['amount'], $log['dose_unit'])) ?>
                <?= $log['notes'] ? '<span class="muted small">— ' . h($log['notes']) . '</span>' : '' ?></span>
            <form method="post" action="<?= h(url('dose_action.php')) ?>" data-confirm="Remove this dose record?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="undo">
                <input type="hidden" name="log_id" value="<?= (int) $log['id'] ?>">
                <input type="hidden" name="return" value="<?= h($return) ?>">
                <button class="btn-link danger" type="submit">Remove</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!empty($day['care'])): $d = new DateTimeImmutable($ymd, $today->getTimezone()); [$doneCount, $dueCount] = care_day_progress($day['care'], $careLogs, $d); ?>
    <h3>Care <span class="muted small"><?= $doneCount ?> of <?= $dueCount ?> done</span></h3>
    <ul class="plain">
        <?php foreach ($day['care'] as $t): $times = care_times($t); $canTick = share_can($t['area'] === 'mind' ? 'mind' : 'care', 'edit'); ?>
        <li class="logline">
            <span><?= CARE_AREAS[$t['area']]['icon'] ?? '' ?> <?= h($t['label']) ?><?= $t['amount'] ? ' <span class="muted small">' . h($t['amount']) . '</span>' : '' ?></span>
            <span class="inline-actions">
            <?php foreach ($times as $slot => $time): $done = care_done($careLogs, $t, $d, $slot);
                $name = count($times) === 1 ? ($done ? 'Done' : 'Mark done') : care_time_label($time); ?>
                <?php if ($canTick): ?>
                <form method="post" action="<?= h(url('care.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="date" value="<?= h($ymd) ?>"><input type="hidden" name="slot" value="<?= (int) $slot ?>">
                    <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>"><input type="hidden" name="return" value="<?= h($return . '#day-' . $ymd) ?>">
                    <button class="btn btn-small<?= $done ? ' btn-primary' : '' ?>" type="submit" aria-pressed="<?= $done ? 'true' : 'false' ?>" title="<?= h($time) ?>"
                        aria-label="<?= h($t['label'] . ', ' . $time . ', ' . $d->format('l j F') . ($done ? ', done' : ', not done')) ?>"><?= $done ? '&#10003; ' : '' ?><?= h($name) ?></button>
                </form>
                <?php else: ?>
                <span class="badge<?= $done ? ' badge-taken' : '' ?>"><?= h(count($times) === 1 ? ($done ? 'Done' : 'Not done') : care_time_label($time) . ($done ? '' : ': not done')) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (!empty($day['selfcare'])): $doneToday = $selfcareDone[$ymd] ?? []; $canTick = share_can('mind', 'edit'); ?>
    <h3>Self-care <span class="muted small"><?= count($doneToday) ?> of <?= count($day['selfcare']) ?> done</span></h3>
    <div class="selfcare">
        <?php foreach ($day['selfcare'] as $item): $done = isset($doneToday[$item]); ?>
        <?php if ($canTick): ?>
        <form method="post" action="<?= h(url('mind.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="selfcare">
            <input type="hidden" name="item" value="<?= h($item) ?>"><input type="hidden" name="date" value="<?= h($ymd) ?>">
            <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>"><input type="hidden" name="return" value="<?= h($return . '#day-' . $ymd) ?>">
            <button class="btn btn-small<?= $done ? ' btn-primary' : '' ?>" type="submit" aria-pressed="<?= $done ? 'true' : 'false' ?>"
                aria-label="<?= h($item . ', ' . date('l j F', strtotime($ymd)) . ($done ? ', done' : ', not done')) ?>"><?= $done ? '&#10003; ' : '' ?><?= h($item) ?></button>
        </form>
        <?php else: ?>
        <span class="badge<?= $done ? ' badge-taken' : '' ?>"><?= h($item) ?></span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php render_footer();
