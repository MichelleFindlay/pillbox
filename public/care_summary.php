<?php
/*
 * Care summary: everything in the Care tab over a period, on one printable page:
 * how often routine tasks were done, checks / screening / vaccinations and when
 * they're due, sleep and cycle figures. For the user, or to show a GP, physio or nurse.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();

$to = parse_date(get('to')) ?? $today;
$to = min($to, $today);
$from = parse_date(get('from')) ?? $to->modify('-29 days');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if ($from < $to->modify('-366 days')) {
    $from = $to->modify('-366 days');
}
$enabled = user_care_areas($user);
$only = isset(CARE_AREAS[get('area')]) ? get('area') : '';
$areas = $only ? [$only] : $enabled;

if (in_array('screening', $areas, true)) {
    sync_screening($user);
}

// Routines: due and done tick-slots per task over the period.
$tasks = array_values(array_filter(care_tasks($uid), fn ($t) => in_array($t['area'], $areas, true)));
$logs = care_logs($uid, $from->format('Y-m-d'), $to->format('Y-m-d'));
$routine = [];
foreach ($tasks as $t) {
    $due = $done = 0;
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        if (care_due($t, $d)) {
            foreach (array_keys(care_times($t)) as $slot) {
                $due++;
                $done += care_done($logs, $t, $d, $slot) ? 1 : 0;
            }
        }
    }
    $routine[$t['area']][] = ['task' => $t, 'due' => $due, 'done' => $done];
}

// Checks, screening and vaccinations.
$checks = [];
foreach (health_checks($uid) as $c) {
    if (in_array($c['area'], $areas, true)) {
        [$due, $note] = check_next_due($c, $user, $today);
        $checks[$c['area']][] = ['check' => $c, 'due' => $due, 'note' => $note, 'status' => check_status($due, $today)];
    }
}
$attention = [];
foreach ($checks as $list) {
    foreach ($list as $row) {
        if (in_array($row['status'], ['overdue', 'soon'], true)) {
            $attention[] = $row;
        }
    }
}
usort($attention, fn ($a, $b) => $a['due'] <=> $b['due']);

// Sleep: nights that ended in the period.
$nights = [];
if (in_array('sleep', $areas, true)) {
    $utc = new DateTimeZone('UTC');
    $nights = q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'sleep' AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at",
        [$uid, $from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')])->fetchAll();
}
$hours = array_map(fn ($n) => (float) $n['value1'], $nights);
$quality = array_count_values(array_filter(array_column($nights, 'context')));

// Cycle: periods starting in the period, and figures from the last 6 cycles.
$periods = $cycle = null;
if (in_array('womens', $areas, true)) {
    $recent = cycle_periods($uid, 12);
    $cycle = cycle_stats($recent, $today->getTimezone());
    $periods = array_values(array_filter($recent, fn ($p) => $p['start_date'] >= $from->format('Y-m-d') && $p['start_date'] <= $to->format('Y-m-d')));
}

$pct = fn (int $done, int $due) => $due ? (int) round(100 * $done / $due) : null;
$range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
$age = $user['date_of_birth'] ? (new DateTimeImmutable($user['date_of_birth']))->diff($today)->y : null;
$statusBadge = ['overdue' => '<span class="badge badge-missed">Overdue</span>', 'soon' => '<span class="badge badge-due">Due soon</span>',
    'later' => '<span class="badge badge-taken">Up to date</span>', 'unknown' => '<span class="badge badge-skipped">No date</span>'];

render_header('Care summary', 'care');
?>
<form method="get" class="card filters no-print">
    <label>From <input type="date" name="from" max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($range['from']) ?>"></label>
    <label>To <input type="date" name="to" max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($range['to']) ?>"></label>
    <label>Category
        <select name="area">
            <option value="">All my categories</option>
            <?php foreach ($enabled as $key): ?><option value="<?= h($key) ?>"<?= selected($only === $key) ?>><?= h(CARE_AREAS[$key]['title']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <button class="btn" type="submit">Update</button>
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php')) ?>">&larr; Care</a>
</form>

<article class="report">
    <header class="report-head">
        <img src="<?= h(asset('assets/logo.svg')) ?>" alt="" width="56" height="56">
        <div>
            <h1>Care summary<?= $only ? ': ' . h(CARE_AREAS[$only]['title']) : '' ?></h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['date_of_birth'] ? ' · born ' . h((new DateTimeImmutable($user['date_of_birth']))->format('j M Y')) . " (age $age)" : '' ?></p>
            <p class="muted"><?= h($from->format('j M Y')) ?> to <?= h($to->format('j M Y')) ?> · generated <?= h((new DateTimeImmutable('now', user_tz()))->format('j M Y H:i')) ?></p>
        </div>
    </header>

    <?php if (!$areas): ?>
    <p class="muted">No Care categories are switched on. <a href="<?= h(url('account.php#health-profile')) ?>">Choose them in your health profile</a>.</p>
    <?php endif; ?>

    <?php if ($attention): ?>
    <section>
        <h2>Needs attention</h2>
        <ul class="plain">
        <?php foreach ($attention as $row): ?>
            <li class="logline"><span><?= CARE_AREAS[$row['check']['area']]['icon'] ?> <strong><?= h($row['check']['label']) ?></strong>
                <span class="muted small"><?= h(due_label($row['due'], $today)) ?></span></span><?= $statusBadge[$row['status']] ?></li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($routine): ?>
    <section>
        <h2>Routines</h2>
        <p class="muted small">How many of the times each task was due it was ticked off, from <?= h($from->format('j M')) ?> to <?= h($to->format('j M')) ?>
            (or from when it was added).</p>
        <?php foreach ($areas as $key): if (empty($routine[$key])) { continue; }
            $areaDue = array_sum(array_column($routine[$key], 'due'));
            $areaDone = array_sum(array_column($routine[$key], 'done')); ?>
        <h3><?= CARE_AREAS[$key]['icon'] ?> <?= h(CARE_AREAS[$key]['title']) ?>
            <?php if ($areaDue): ?><span class="muted small">(<?= $pct($areaDone, $areaDue) ?>% overall)</span><?php endif; ?></h3>
        <div class="table-wrap">
        <table class="compact care-summary-table">
            <thead><tr><th>Task</th><th class="num">Done</th><th>Completion</th></tr></thead>
            <tbody>
            <?php foreach ($routine[$key] as $r): $t = $r['task']; $p = $pct($r['done'], $r['due']); ?>
                <tr>
                    <td><strong><?= h($t['label']) ?></strong><?= $t['amount'] ? ' <span class="amount-chip">' . h($t['amount']) . '</span>' : '' ?>
                        <br><span class="muted small"><?= h(implode(', ', care_times($t))) ?> &middot; <?= h(care_repeat_label($t)) ?></span></td>
                    <td class="num nowrap"><?= $r['done'] ?> of <?= $r['due'] ?></td>
                    <td class="nowrap"><?php if ($p === null): ?><span class="muted small">Not due in this period</span><?php else: ?>
                        <span class="meter" role="img" aria-label="<?= $p ?>%"><svg viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect class="meter-track" width="100" height="6" rx="3"/><rect class="meter-fill" width="<?= $p ?>" height="6" rx="3"/></svg></span>
                        <strong><?= $p ?>%</strong><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($checks): ?>
    <section>
        <h2>Checks, screening and vaccinations</h2>
        <div class="table-wrap">
        <table class="compact care-summary-table">
            <thead><tr><th>Item</th><th>Last done</th><th>Next due or level</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($areas as $key): if (empty($checks[$key])) { continue; } ?>
                <tr class="group-row"><th colspan="4"><?= CARE_AREAS[$key]['icon'] ?> <?= h(CARE_AREAS[$key]['title']) ?></th></tr>
                <?php foreach ($checks[$key] as $row): $c = $row['check']; ?>
                <tr>
                    <td><strong><?= h($c['label']) ?></strong>
                        <br><span class="muted small"><?= $c['rule'] === 'autumn' ? 'Every autumn' : ($c['interval_days'] ? h(ucfirst(interval_label((int) $c['interval_days']))) : 'One-off') ?></span>
                        <?= $c['notes'] ? '<br><span class="small">' . h($c['notes']) . '</span>' : '' ?></td>
                    <td><?= $c['last_done'] ? h((new DateTimeImmutable($c['last_done']))->format('j M Y')) : '<span class="muted">&ndash;</span>' ?>
                        <?= $c['last_done'] && $c['last_done'] >= $range['from'] && $c['last_done'] <= $range['to'] ? '<br><span class="small">in this period</span>' : '' ?></td>
                    <td><?= ($text = check_due_text($c, $row['due'], $today)) ? h($text) : '<span class="muted small">' . h($row['note']) . '</span>' ?></td>
                    <td><?= $statusBadge[$row['status']] ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($nights): ?>
    <section>
        <h2>&#x1F319; Sleep</h2>
        <div class="tiles">
            <div class="tile"><span class="tile-label">Average</span><span class="tile-value"><?= h(format_hours(array_sum($hours) / count($hours))) ?></span><span class="tile-meta"><?= count($nights) ?> nights logged</span></div>
            <div class="tile"><span class="tile-label">Shortest</span><span class="tile-value"><?= h(format_hours(min($hours))) ?></span></div>
            <div class="tile"><span class="tile-label">Longest</span><span class="tile-value"><?= h(format_hours(max($hours))) ?></span></div>
            <div class="tile"><span class="tile-label">Under 7 hours</span><span class="tile-value"><?= count(array_filter($hours, fn ($x) => $x < 7)) ?></span><span class="tile-meta">nights</span></div>
        </div>
        <?php if ($quality): ?>
        <p class="small">How you slept: <?= h(implode(', ', array_map(fn ($q, $n) => "$q $n", array_keys($quality), $quality))) ?>.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($cycle && ($periods || $cycle['cycle'])): ?>
    <section>
        <h2><?= CARE_AREAS['womens']['icon'] ?> Cycle</h2>
        <div class="tiles">
            <?php if ($cycle['cycle']): ?><div class="tile"><span class="tile-label">Average cycle</span><span class="tile-value"><?= (int) round($cycle['cycle']) ?> days</span>
                <span class="tile-meta"><?= $cycle['cycles'] > 1 ? $cycle['shortest'] . ' to ' . $cycle['longest'] . ' days' : '1 cycle' ?></span></div><?php endif; ?>
            <?php if ($cycle['period']): ?><div class="tile"><span class="tile-label">Average period</span><span class="tile-value"><?= round($cycle['period'], 1) ?> days</span></div><?php endif; ?>
            <?php if ($cycle['next']): ?><div class="tile"><span class="tile-label">Next expected</span><span class="tile-value"><?= h($cycle['next']->format('j M')) ?></span></div><?php endif; ?>
        </div>
        <?php if ($periods): ?>
        <p class="small">Periods started in this period: <?= h(implode(', ', array_map(fn ($p) => (new DateTimeImmutable($p['start_date']))->format('j M')
            . ($p['end_date'] ? ' (' . ((new DateTimeImmutable($p['start_date']))->diff(new DateTimeImmutable($p['end_date']))->days + 1) . ' days' . ($p['flow'] ? ', ' . $p['flow'] : '') . ')' : ''), $periods))) ?>.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($areas && !$routine && !$checks && !$nights && !$periods): ?>
    <p class="muted">Nothing recorded for <?= $only ? h(CARE_AREAS[$only]['title']) : 'your Care categories' ?> yet. <a href="<?= h(url('care.php')) ?>">Set up routines and checks in Care</a>.</p>
    <?php endif; ?>
</article>
<?php render_footer();
