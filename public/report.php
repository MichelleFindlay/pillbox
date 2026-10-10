<?php
/*
 * A printable summary to take to (or send to) a doctor or pharmacist.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();

$to = parse_date(get('to')) ?? $today;
$from = parse_date(get('from')) ?? $to->modify('-29 days');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if ($from < $to->modify('-366 days')) {
    $from = $to->modify('-366 days');
}

$utc = new DateTimeZone('UTC');
$fromUtc = $from->setTimezone($utc)->format('Y-m-d H:i:s');
$toUtc = $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

$meds = user_medications($uid);
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
$adherence = adherence(dose_slots($user, $from, $to));
$prn = prn_logs($uid, $fromUtc, $toUtc);
$prnTotals = [];
foreach ($prn as $log) {
    $prnTotals[$log['name']] ??= ['count' => 0, 'amount' => 0.0, 'unit' => $log['dose_unit']];
    $prnTotals[$log['name']]['count']++;
    $prnTotals[$log['name']]['amount'] += (float) $log['amount'];
}

$appointments = appointments_between($uid, $fromUtc, $toUtc);
$medChanges = q('SELECT c.*, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id
                 WHERE c.user_id = ? AND c.effective_date BETWEEN ? AND ? ORDER BY c.effective_date, m.name',
    [$uid, $from->format('Y-m-d'), $to->format('Y-m-d')])->fetchAll();
$nextAppointments = appointments_between($uid, utc_now(), gmdate('Y-m-d H:i:s', strtotime('+6 months')));

$defs = user_metric_definitions($uid);
$readingsByMetric = [];
foreach (q('SELECT * FROM health_readings WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at', [$uid, $fromUtc, $toUtc]) as $r) {
    if (isset($defs[$r['metric']])) {
        $readingsByMetric[$r['metric']][] = $r;
    }
}

$range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

render_header('Report', 'report');
?>
<form method="get" class="card filters no-print">
    <label>From <input type="date" name="from" value="<?= h($range['from']) ?>"></label>
    <label>To <input type="date" name="to" value="<?= h($range['to']) ?>"></label>
    <button class="btn" type="submit">Update</button>
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('export.php?' . http_build_query($range + ['what' => 'doses']))) ?>">Doses CSV</a>
    <a class="btn" href="<?= h(url('export.php?' . http_build_query($range + ['what' => 'readings']))) ?>">Readings CSV</a>
    <a class="btn" href="<?= h(url('care_summary.php?' . http_build_query($range))) ?>">Care summary</a>
</form>

<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Medication &amp; health report</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['email'] ? ' · ' . h($user['email']) : '' ?></p>
            <p class="muted"><?= h($from->format('j M Y')) ?> to <?= h($to->format('j M Y')) ?> · generated <?= h((new DateTimeImmutable('now', user_tz()))->format('j M Y H:i')) ?></p>
        </div>
    </header>

    <section>
        <h2>Current medication</h2>
        <?php if (!$meds): ?><p class="muted">None recorded.</p><?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Medication</th><th>Dose &amp; timing</th><th>For</th><th>Prescriber</th><th>Since</th></tr></thead>
            <tbody>
            <?php foreach ($meds as $m): ?>
                <tr>
                    <td><strong><?= h($m['name']) ?></strong> <?= h($m['strength']) ?><br><span class="muted small"><?= h(ucfirst($m['form'])) ?><?= $m['dmd_code'] ? ' · dm+d ' . h($m['dmd_code']) : '' ?></span></td>
                    <td>
                        <?php if ($m['as_needed']): ?>
                            As needed<?= $m['prn_dose_amount'] ? ', ' . h(dose_label($m['prn_dose_amount'], $m['dose_unit'])) : '' ?><?= $m['prn_max_daily'] ? ' (max ' . h(dose_label($m['prn_max_daily'], $m['dose_unit'])) . '/day)' : '' ?>
                        <?php else: foreach (schedule_lines($schedules[$m['id']] ?? [], $m['dose_unit']) as $line): ?>
                            <div><?= h($line) ?></div>
                        <?php endforeach; endif; ?>
                        <?= $m['instructions'] ? '<div class="muted small">' . h($m['instructions']) . '</div>' : '' ?>
                    </td>
                    <td><?= h($m['reason']) ?></td>
                    <td><?= h($m['prescriber']) ?></td>
                    <td><?= h(date('j M Y', strtotime($m['start_date']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($medChanges): ?>
    <section>
        <h2>Medication changes in this period</h2>
        <div class="table-wrap">
        <table class="compact">
            <thead><tr><th>Date</th><th>Medication</th><th>Change</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($medChanges as $c): ?>
                <tr><td><?= h(date('j M Y', strtotime($c['effective_date']))) ?></td><td><?= h($c['name']) ?></td><td><?= h($c['summary']) ?></td><td><?= h($c['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <section>
        <h2>Adherence to scheduled doses</h2>
        <?php if (!$adherence): ?><p class="muted">No scheduled doses in this period.</p><?php else: ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Medication</th><th class="num">Expected</th><th class="num">Taken</th><th class="num">Skipped</th><th class="num">Missed</th><th class="num">Adherence</th></tr></thead>
            <tbody>
            <?php foreach ($adherence as $a): ?>
                <tr>
                    <td><?= h($a['name']) ?> <?= h($a['strength']) ?></td>
                    <td class="num"><?= $a['expected'] ?></td>
                    <td class="num"><?= $a['taken'] ?></td>
                    <td class="num"><?= $a['skipped'] ?></td>
                    <td class="num"><?= $a['missed'] ?></td>
                    <td class="num"><strong><?= $a['pct'] ?>%</strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($prnTotals): ?>
    <section>
        <h2>As-needed medication used</h2>
        <ul>
            <?php foreach ($prnTotals as $name => $t): ?>
            <li><?= h($name) ?>: <?= $t['count'] ?> dose<?= $t['count'] === 1 ? '' : 's' ?>, <?= h(dose_label($t['amount'], $t['unit'])) ?> in total</li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($appointments || $nextAppointments): ?>
    <section>
        <h2>Appointments</h2>
        <div class="table-wrap">
        <table class="compact">
            <thead><tr><th>When</th><th>Appointment</th><th>With</th><th>Where</th></tr></thead>
            <tbody>
            <?php foreach (array_merge($appointments, array_filter($nextAppointments, fn ($n) => $n['starts_at'] >= $toUtc)) as $a): ?>
                <tr><td><?= h(local_time($a['starts_at'], 'D j M Y, H:i')) ?><?= $a['starts_at'] > utc_now() ? ' <span class="muted small">(upcoming)</span>' : '' ?></td>
                    <td><?= h(appointment_title($a)) ?><?= $a['title'] ? ' <span class="muted small">(' . h(appointment_type_label($a['kind'])) . ')</span>' : '' ?></td>
                    <td><?= h($a['with_whom']) ?></td><td><?= h($a['location']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

    <section>
        <h2>Health readings</h2>
        <?php if (!$readingsByMetric): ?><p class="muted">No readings in this period.</p><?php endif; ?>
        <?php foreach ($readingsByMetric as $key => $rows): $def = $defs[$key]; ?>
        <div class="report-metric">
            <h3><?= h($def['label']) ?> <span class="muted small">(<?= count($rows) ?> reading<?= count($rows) === 1 ? '' : 's' ?>)</span></h3>
            <dl class="stats">
                <?php foreach (metric_stats($def, $rows) as $st): ?>
                <div><dt><?= h($st['label']) ?></dt><dd>avg <?= h(num(round($st['avg'], 1))) ?> · min <?= h(num($st['min'])) ?> · max <?= h(num($st['max'])) ?> <?= h($st['unit']) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <div class="table-wrap">
            <table class="compact">
                <thead><tr><th>When</th><th>Reading</th><th>Context</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($rows) as $r): ?>
                    <tr><td><?= h(local_time($r['recorded_at'], 'D j M Y, H:i')) ?></td><td><?= h(format_reading($def, $r)) ?></td><td><?= h($r['context']) ?></td><td><?= h($r['notes']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
</article>
<?php render_footer();
