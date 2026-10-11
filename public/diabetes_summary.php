<?php
/*
 * Diabetes: a one-page summary for a diabetes team or GP: type and targets, medicines (hypo
 * risk), HbA1c trend, glucose time in range over 14 and 90 days and by time of day, hypos,
 * high ketones, average daily insulin and carbs, the annual care checks, and the latest blood
 * pressure and weight. Printable.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
if (!share_area_allowed('diabetes')) {
    redirect('care.php');
}
$tz = user_tz($user);
$p = diabetes_profile($uid);
$low = (float) $p['target_low'];
$high = (float) $p['target_high'];
$s14 = glucose_stats(glucose_points($uid, time() - 14 * 86400), $low, $high, $tz);
$s90 = glucose_stats(glucose_points($uid, time() - 90 * 86400), $low, $high, $tz);
$hba1c = q("SELECT * FROM lab_results WHERE user_id = ? AND test = 'hba1c' ORDER BY taken_at", [$uid])->fetchAll();
$meds = array_filter(user_medications($uid), fn ($m) => preg_match(DIABETES_MED_PATTERN, $m['name']) || preg_match(HYPO_RISK_PATTERN, $m['name']) || preg_match(SGLT2_PATTERN, $m['name']));
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
$hypos = q('SELECT * FROM hypo_events WHERE user_id = ? AND at >= ? ORDER BY at DESC', [$uid, gmdate('Y-m-d H:i:s', time() - 90 * 86400)])->fetchAll();
$ketones = q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'blood_ketones' AND value1 >= 1.5 AND recorded_at >= ? ORDER BY recorded_at DESC", [$uid, gmdate('Y-m-d H:i:s', time() - 90 * 86400)])->fetchAll();
$ins = q('SELECT COUNT(DISTINCT DATE(taken_at)) AS days, SUM(CASE WHEN kind = \'basal\' THEN units ELSE 0 END) AS basal, SUM(CASE WHEN kind <> \'basal\' THEN units ELSE 0 END) AS bolus, SUM(carbs) AS carbs
          FROM insulin_logs WHERE user_id = ? AND taken_at >= ?', [$uid, gmdate('Y-m-d H:i:s', time() - 14 * 86400)])->fetch();
$annual = [];
foreach (q("SELECT * FROM health_checks WHERE user_id = ? AND area = 'diabetes' AND kind LIKE 'dm\\_%'", [$uid]) as $c) {
    $annual[$c['kind']] = $c;
}
$latest = fn (string $metric) => q('SELECT * FROM health_readings WHERE user_id = ? AND metric = ? ORDER BY recorded_at DESC LIMIT 1', [$uid, $metric])->fetch();
$bp = $latest('blood_pressure');
$weight = $latest('weight');

render_header('Diabetes summary', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=diabetes')) ?>">&larr; Diabetes</a>
</div>

<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Diabetes summary</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['pronouns'] ? ' (' . h($user['pronouns']) . ')' : '' ?><?= $user['date_of_birth'] ? ' · born ' . h(date('j M Y', strtotime($user['date_of_birth']))) : '' ?></p>
            <p class="muted"><?= $p['type'] ? h(DIABETES_TYPES[$p['type']]) . ' · ' : '' ?>glucose target <?= h(num($low)) ?> to <?= h(num($high)) ?> mmol/L<?= $p['hba1c_target'] ? ' · HbA1c target ' . (int) $p['hba1c_target'] . ' mmol/mol' : '' ?> · generated <?= h($today->format('j M Y')) ?></p>
        </div>
    </header>

    <section>
        <h2>Medicines</h2>
        <?php if (!$meds): ?><p class="muted">None recorded.</p><?php endif; ?>
        <ul><?php foreach ($meds as $m): ?><li><strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> · <?= h(medication_regimen($m, $schedules[$m['id']] ?? [])) ?>
            <?= preg_match(HYPO_RISK_PATTERN, $m['name']) ? ' · <em>can cause hypos</em>' : '' ?></li><?php endforeach; ?></ul>
        <?php if ((int) $ins['days']): ?><p class="small">Last 14 days (<?= (int) $ins['days'] ?> days logged): about <?= h(num(round($ins['basal'] / $ins['days'], 1))) ?> units basal and <?= h(num(round($ins['bolus'] / $ins['days'], 1))) ?> units bolus a day,
            <?= h(num(round($ins['carbs'] / $ins['days']))) ?> g carbohydrate.</p><?php endif; ?>
    </section>

    <section>
        <h2>HbA1c</h2>
        <?php if (count($hba1c) > 1): $pts = array_map(fn ($r) => [strtotime($r['taken_at'] . ' UTC'), (float) $r['value']], $hba1c); ?>
        <?= svg_line_chart([['label' => 'HbA1c', 'unit' => 'mmol/mol', 'points' => $pts]], $pts[0][0] - 86400 * 20, end($pts)[0] + 86400 * 20, 'HbA1c',
            $p['hba1c_target'] ? [['low' => null, 'high' => (float) $p['hba1c_target'], 'class' => 'band-target', 'label' => 'At or under target']] : []) ?>
        <?php endif; ?>
        <p><?= $hba1c ? h(implode(' · ', array_map(fn ($r) => local_time($r['taken_at'], 'M Y') . ': ' . num($r['value']), array_slice($hba1c, -6)))) . ' mmol/mol' : 'No results recorded.' ?></p>
    </section>

    <section>
        <h2>Glucose</h2>
        <?php if (!$s90): ?><p class="muted">No readings recorded.</p><?php else: ?>
        <table><thead><tr><th></th><th class="num">Readings</th><th class="num">Average</th><th class="num">Very low &lt;3.0</th><th class="num">Low</th><th class="num">In range</th><th class="num">High</th><th class="num">Very high &gt;13.9</th></tr></thead><tbody>
            <?php foreach (['Last 14 days' => $s14, 'Last 90 days' => $s90] as $label => $s): if ($s): ?>
            <tr><td><?= h($label) ?></td><td class="num"><?= $s['n'] ?></td><td class="num"><?= h(number_format($s['mean'], 1)) ?></td><td class="num"><?= (int) round($s['very_low']) ?>%</td><td class="num"><?= (int) round($s['low']) ?>%</td>
                <td class="num"><strong><?= (int) round($s['in_range']) ?>%</strong></td><td class="num"><?= (int) round($s['high']) ?>%</td><td class="num"><?= (int) round($s['very_high']) ?>%</td></tr>
            <?php endif; endforeach; ?>
        </tbody></table>
        <p class="small">By time of day (90 days): <?= h(implode(' · ', array_filter(array_map(fn ($part, $x) => $x ? "$part average " . number_format($x['mean'], 1) . ', ' . round($x['in_range']) . '% in range, ' . round($x['low']) . '% low' : null,
            array_keys($s90['parts']), $s90['parts'])))) ?>.</p>
        <?php endif; ?>
        <p class="small"><?= count($hypos) ?> hypo<?= count($hypos) === 1 ? '' : 's' ?> logged with the hypo guide in 90 days; <?= hypo_episodes($uid, time() - 90 * 86400) ?> low episodes in total (readings under 4).
            <?= $ketones ? count($ketones) . ' ketone reading' . (count($ketones) === 1 ? '' : 's') . ' of 1.5 or more (latest ' . h(local_time($ketones[0]['recorded_at'], 'j M')) . ': ' . h(num($ketones[0]['value1'])) . ').' : '' ?></p>
        <?php if ($hypos): ?><ul class="small"><?php foreach (array_slice($hypos, 0, 8) as $hy): ?><li><?= h(local_time($hy['at'], 'j M H:i')) ?><?= $hy['glucose'] ? ': ' . h(num($hy['glucose'])) . ' mmol/L' : '' ?><?= $hy['notes'] ? ' · ' . h($hy['notes']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section>
        <h2>Annual care checks</h2>
        <ul class="annual-list"><?php foreach (DIABETES_ANNUAL as $kind => $label): $c = $annual[$kind] ?? null; $done = $c && $c['last_done'] && $c['last_done'] >= $today->modify('-12 months')->format('Y-m-d'); ?>
            <li><?= $done ? '&#10003;' : '&#9675;' ?> <?= h($label) ?> <span class="muted small"><?= $c && $c['last_done'] ? h(date('M Y', strtotime($c['last_done']))) : 'not recorded' ?></span></li>
        <?php endforeach; ?></ul>
        <p class="small"><?= $bp ? 'Latest blood pressure ' . (int) $bp['value1'] . '/' . (int) $bp['value2'] . ' (' . h(local_time($bp['recorded_at'], 'j M Y')) . ')' : 'No blood pressure recorded' ?>.
            <?= $weight ? 'Latest weight ' . h(num($weight['value1'])) . ' kg (' . h(local_time($weight['recorded_at'], 'j M Y')) . ').' : '' ?></p>
    </section>

    <section>
        <h2>Questions and notes</h2>
        <div class="write-lines"></div>
    </section>
</article>
<?php render_footer();
