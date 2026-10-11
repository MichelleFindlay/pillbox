<?php
/*
 * Blood pressure: a one-page summary for a GP or practice nurse: target, the latest home
 * monitoring average (NICE method), morning and evening averages, very high readings and
 * irregular heartbeat flags, the chart with medicine changes, medicines and side effects, and
 * the latest kidney and potassium results. Printable.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
if (!share_area_allowed('bp')) {
    redirect('care.php');
}
$p = bp_profile($uid);
[$tSys, $tDia, $tWhy] = bp_target($user, $p);
$session = bp_session($user, $p, $today);
$readings = bp_readings($uid, gmdate('Y-m-d H:i:s', time() - 90 * 86400));
$avg = fn (array $rs) => $rs ? (int) round(array_sum(array_column($rs, 'value1')) / count($rs)) . '/' . (int) round(array_sum(array_column($rs, 'value2')) / count($rs)) : '–';
$am = array_filter($readings, fn ($r) => (int) local_time($r['recorded_at'], 'G') < 13);
$pm = array_filter($readings, fn ($r) => (int) local_time($r['recorded_at'], 'G') >= 13);
$pulses = array_filter(array_column($readings, 'value3'));
$veryHigh = array_filter($readings, fn ($r) => $r['value1'] >= 180 || $r['value2'] >= 120);
$irregular = count(array_filter($readings, fn ($r) => stripos((string) $r['notes'], 'irregular') !== false));
$meds = array_filter(user_medications($uid, false), fn ($m) => bp_class($m['name']));
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
$ids = array_map('intval', array_column($meds, 'id'));
$changes = $ids ? q('SELECT c.*, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id WHERE c.medication_id IN (' . implode(',', $ids) . ') ORDER BY c.effective_date DESC LIMIT 12')->fetchAll() : [];
$effects = $ids ? q("SELECT n.*, m.name FROM med_notes n JOIN medications m ON m.id = n.medication_id WHERE n.kind = 'side_effect' AND n.medication_id IN (" . implode(',', $ids) . ') ORDER BY n.created_at DESC LIMIT 10')->fetchAll() : [];
$labs = [];
foreach (q("SELECT * FROM lab_results WHERE user_id = ? AND test IN ('egfr', 'creatinine', 'potassium', 'sodium') ORDER BY taken_at", [$uid]) as $r) {
    $labs[$r['test']] = $r;
}

render_header('Blood pressure summary', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=bp')) ?>">&larr; Blood pressure</a>
</div>
<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Blood pressure summary</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['pronouns'] ? ' (' . h($user['pronouns']) . ')' : '' ?><?= $user['date_of_birth'] ? ' · born ' . h(date('j M Y', strtotime($user['date_of_birth']))) : '' ?></p>
            <p class="muted">Home target below <?= $tSys ?>/<?= $tDia ?> (<?= h($tWhy) ?>)<?= $p['monitor'] ? ' · monitor: ' . h($p['monitor']) . ($p['validated'] ? ' (validated)' : '') : '' ?><?= $p['cuff'] ? ', ' . h($p['cuff']) . ' cuff' : '' ?> · generated <?= h($today->format('j M Y')) ?></p>
        </div>
    </header>
    <section>
        <h2>Home readings</h2>
        <?php if ($session && $session['average']): ?>
        <p><strong>Home monitoring <?= h(date('j M', strtotime($session['start']))) ?> to <?= h(date('j M Y', strtotime($session['end']))) ?>:</strong>
            average <strong><?= (int) round($session['average']['sys']) ?>/<?= (int) round($session['average']['dia']) ?></strong> from <?= $session['average']['n'] ?> readings over <?= $session['average']['days'] ?> days (day 1 left out, NICE method).</p>
        <?php endif; ?>
        <table><thead><tr><th>Last 90 days</th><th class="num">Readings</th><th class="num">Average</th></tr></thead><tbody>
            <tr><td>Morning</td><td class="num"><?= count($am) ?></td><td class="num"><?= $avg($am) ?></td></tr>
            <tr><td>Evening</td><td class="num"><?= count($pm) ?></td><td class="num"><?= $avg($pm) ?></td></tr>
            <tr><td><strong>All</strong></td><td class="num"><?= count($readings) ?></td><td class="num"><strong><?= $avg($readings) ?></strong></td></tr>
        </tbody></table>
        <p class="small"><?= $pulses ? 'Average pulse ' . (int) round(array_sum($pulses) / count($pulses)) . ' bpm. ' : '' ?><?= $veryHigh ? count($veryHigh) . ' reading' . (count($veryHigh) === 1 ? '' : 's') . ' of 180/120 or more. ' : '' ?>
            <?= $irregular ? "Irregular heartbeat flagged $irregular time" . ($irregular === 1 ? '' : 's') . ' by the monitor.' : '' ?></p>
        <?php if (count($readings) > 1): $ts = fn ($r) => strtotime($r['recorded_at'] . ' UTC'); ?>
        <?= svg_line_chart([['label' => 'Systolic', 'unit' => 'mmHg', 'points' => array_map(fn ($r) => [$ts($r), (float) $r['value1']], $readings)],
            ['label' => 'Diastolic', 'unit' => 'mmHg', 'points' => array_map(fn ($r) => [$ts($r), (float) $r['value2']], $readings)]],
            time() - 90 * 86400, time(), 'Blood pressure', [], medication_change_markers($uid, fn ($n) => bp_class($n) !== null)) ?>
        <p class="muted small">Dashed lines: medicine changes.</p>
        <?php endif; ?>
    </section>
    <section>
        <h2>Medicines</h2>
        <?php if (!$meds): ?><p class="muted">None recorded.</p><?php endif; ?>
        <ul><?php foreach ($meds as $m): ?><li><strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> (<?= h(BP_CLASSES[bp_class($m['name'])][0]) ?>) ·
            <?= $m['is_active'] ? h(medication_regimen($m, $schedules[$m['id']] ?? [])) : 'stopped' ?></li><?php endforeach; ?></ul>
        <?php if ($changes): ?><p class="small">Changes: <?= h(implode(' · ', array_map(fn ($c) => date('j M Y', strtotime($c['effective_date'])) . ' ' . $c['name'] . ': ' . lcfirst($c['summary']), $changes))) ?></p><?php endif; ?>
        <?php if ($effects): ?><p class="small">Side effects noted: <?= h(implode(' · ', array_map(fn ($n) => $n['note'] . ' (' . $n['name'] . ', ' . local_time($n['created_at'], 'j M') . ')', $effects))) ?></p><?php endif; ?>
    </section>
    <section>
        <h2>Kidney function and potassium</h2>
        <p class="small"><?= $labs ? h(implode(' · ', array_map(fn ($r) => LAB_TESTS[$r['test']][0] . ' ' . num($r['value']) . ' ' . $r['unit'] . ' (' . local_time($r['taken_at'], 'j M Y') . ')', $labs))) : 'No results recorded.' ?></p>
    </section>
    <section>
        <h2>Questions and notes</h2>
        <div class="write-lines"></div>
    </section>
</article>
<?php render_footer();
