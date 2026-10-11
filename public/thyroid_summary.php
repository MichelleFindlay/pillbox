<?php
/*
 * Thyroid: a one-page summary for a GP or endocrinologist: current dose and brand, dose
 * history, TSH and free T4 (chart with dose changes), weekly symptoms, doses logged over the
 * last 90 days, and other medicines that affect absorption. Printable.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
if (!share_area_allowed('thyroid')) {
    redirect('care.php');
}

$meds = user_medications($uid);
$allMeds = user_medications($uid, false);
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
$thyroid = thyroid_meds($meds);
$thyroidIds = array_column(thyroid_meds($allMeds), 'id');
$changes = $thyroidIds ? q('SELECT c.*, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id WHERE c.medication_id IN ('
    . implode(',', array_map('intval', $thyroidIds)) . ') ORDER BY c.effective_date DESC, c.id DESC LIMIT 20')->fetchAll() : [];
$labs = q("SELECT * FROM lab_results WHERE user_id = ? AND test IN ('tsh', 'free_t4', 'free_t3', 'tpo') ORDER BY taken_at", [$uid])->fetchAll();
$tsh = array_values(array_filter($labs, fn ($r) => $r['test'] === 'tsh'));
$targets = lab_targets($uid);
$weeks = thyroid_weeks($uid, 12);
$spacing = spacing_clashes($meds, $schedules);
$interacting = array_filter($meds, fn ($m) => preg_match(SPACING_PATTERN, $m['name'] . ' ' . $m['reason'] . ' ' . $m['instructions']) && !preg_match(THYROID_PATTERN, $m['name']));
// Doses logged over the last 90 days.
$adherence = [];
foreach ($thyroid as $m) {
    $slots = array_filter(dose_slots($user, $today->modify('-90 days'), $today->modify('-1 day'), false, (int) $m['id']), fn ($s) => $s['status'] !== 'upcoming');
    $adherence[$m['id']] = [count(array_filter($slots, fn ($s) => $s['status'] === 'taken')), count($slots)];
}

render_header('Thyroid summary', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=thyroid')) ?>">&larr; Thyroid</a>
</div>

<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Thyroid summary</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['pronouns'] ? ' (' . h($user['pronouns']) . ')' : '' ?><?= $user['date_of_birth'] ? ' · born ' . h(date('j M Y', strtotime($user['date_of_birth']))) : '' ?></p>
            <p class="muted">Generated <?= h($today->format('j M Y')) ?> from my own records in Pillbox</p>
        </div>
    </header>

    <section>
        <h2>Current treatment</h2>
        <?php if (!$thyroid): ?><p class="muted">No thyroid medicine recorded.</p><?php endif; ?>
        <ul><?php foreach ($thyroid as $m): [$taken, $due] = $adherence[$m['id']]; ?>
            <li><strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> · <?= h(medication_regimen($m, $schedules[$m['id']] ?? [])) ?><?= $m['brand'] ? ' · brand: ' . h($m['brand']) : '' ?>
                <br><span class="small">Last 90 days: <?= $taken ?> of <?= $due ?> doses logged as taken<?= $due ? ' (' . (int) round(100 * $taken / $due) . '%)' : '' ?></span></li>
        <?php endforeach; ?></ul>
    </section>

    <?php if ($changes): ?>
    <section>
        <h2>Dose history</h2>
        <table><thead><tr><th>Date</th><th>Change</th></tr></thead><tbody>
            <?php foreach ($changes as $c): ?><tr><td><?= h(date('j M Y', strtotime($c['effective_date']))) ?></td><td><?= h($c['name']) ?>: <?= h($c['summary']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>
    <?php endif; ?>

    <section>
        <h2>Blood tests</h2>
        <?php if (count($tsh) > 1):
            $t = $targets['tsh'] ?? null;
            $bands = $t ? [['low' => $t['low'], 'high' => $t['high'], 'class' => 'band-target', 'label' => 'Target range']] : [];
            $pts = array_map(fn ($r) => [strtotime($r['taken_at'] . ' UTC'), (float) $r['value']], $tsh); ?>
        <?= svg_line_chart([['label' => 'TSH', 'unit' => 'mU/L', 'points' => $pts]], $pts[0][0] - 86400 * 14, end($pts)[0] + 86400 * 14, 'TSH with dose changes', $bands, thyroid_dose_markers($uid)) ?>
        <p class="muted small">Dashed lines: dose changes.<?= $t ? ' Shaded: target range ' . h(num($t['low'] ?? '')) . '–' . h(num($t['high'] ?? '')) . ' mU/L.' : '' ?></p>
        <?php endif; ?>
        <?php if (!$labs): ?><p class="muted">No results recorded.</p><?php else: ?>
        <table><thead><tr><th>Date</th><th>Test</th><th class="num">Result</th><th>Timing</th></tr></thead><tbody>
            <?php foreach (array_reverse(array_slice($labs, -12)) as $r): ?>
            <tr><td><?= h(local_time($r['taken_at'], 'j M Y')) ?></td><td><?= h(LAB_TESTS[$r['test']][0] ?? $r['test']) ?></td><td class="num"><?= h(num($r['value'])) ?> <?= h($r['unit']) ?></td>
                <td class="small"><?= $r['hours_since_dose'] !== null ? h(since_dose_label((int) $r['hours_since_dose'])) : '' ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>

    <?php if ($weeks): ?>
    <section>
        <h2>Weekly symptoms (0 none to 3 a lot)</h2>
        <table><thead><tr><th>Week of</th><th class="num">Too little (of <?= count(THYROID_UNDER) * 3 ?>)</th><th class="num">Too much (of <?= count(THYROID_OVER) * 3 ?>)</th><th>Worst</th></tr></thead><tbody>
            <?php foreach (array_reverse($weeks) as $w): $top = $w['scores']; arsort($top); $top = array_slice(array_filter($top), 0, 3, true); ?>
            <tr><td><?= h(date('j M', strtotime($w['week_start']))) ?></td><td class="num"><?= (int) $w['under'] ?></td><td class="num"><?= (int) $w['over'] ?></td>
                <td class="small"><?= h(implode(', ', array_map(fn ($k) => (THYROID_UNDER + THYROID_OVER)[$k] ?? $k, array_keys($top)))) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    </section>
    <?php endif; ?>

    <section>
        <h2>Other medicines that affect absorption</h2>
        <?php if (!$interacting): ?><p class="muted">None recorded.</p><?php else: ?>
        <ul><?php foreach ($interacting as $m): ?><li><?= h($m['name']) ?> <?= h((string) $m['strength']) ?> · <?= h(medication_regimen($m, $schedules[$m['id']] ?? [])) ?></li><?php endforeach; ?></ul>
        <?php foreach ($spacing['clashes'] as [$t, $tTime, $o, $oTime, $gap]): ?><p class="small warn">Taken <?= h(num(round($gap, 1))) ?> hours apart: <?= h($t['name']) ?> <?= h($tTime) ?>, <?= h($o['name']) ?> <?= h($oTime) ?>.</p><?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section>
        <h2>Questions and notes</h2>
        <div class="write-lines"></div>
    </section>
</article>
<?php render_footer();
