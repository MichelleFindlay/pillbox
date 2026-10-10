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

$utc = new DateTimeZone('UTC');
$slots = dose_slots($user, $from, $to, false, $medFilter);
$prn = prn_logs($uid, $from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'));
if ($medFilter) {
    $prn = array_filter($prn, fn ($l) => (int) $l['medication_id'] === $medFilter);
}
$adherence = adherence($slots);
$meds = user_medications($uid, false);

// Group slots and PRN doses by local day, newest first.
$days = [];
foreach ($slots as $slot) {
    $days[$slot['local']->format('Y-m-d')]['slots'][] = $slot;
}
foreach ($prn as $log) {
    $days[local_time($log['taken_at'], 'Y-m-d')]['prn'][] = $log;
}
krsort($days);

$return = 'history.php?' . http_build_query(array_filter(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'med' => $medFilter]));

render_header('History', 'history');
?>
<div class="page-head">
    <h1>Dose history</h1>
</div>

<form method="get" class="card filters">
    <label>From <input type="date" name="from" value="<?= h($from->format('Y-m-d')) ?>"></label>
    <label>To <input type="date" name="to" value="<?= h($to->format('Y-m-d')) ?>"></label>
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

<?php if (!$days): ?>
<section class="card"><p class="muted">No doses in this period.</p></section>
<?php endif; ?>

<?php foreach ($days as $ymd => $day): ?>
<section class="card">
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
</section>
<?php endforeach; ?>
<?php render_footer();
