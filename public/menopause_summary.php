<?php
/*
 * Menopause: a one-page summary for a GP appointment: stage and periods, symptoms over the
 * last 3 months (and triggers), symptom scores, current treatment and checks. Printable.
 * Work notes are left out.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
if (!share_area_allowed('menopause')) {
    redirect('care.php');
}

$profile = menopause_profile($uid);
$last = last_period_day($uid);
$age = user_age($user);
$periods = q('SELECT * FROM cycle_periods WHERE user_id = ? AND start_date >= ? ORDER BY start_date DESC', [$uid, $today->modify('-12 months')->format('Y-m-d')])->fetchAll();
$logs = menopause_logs($uid, $today->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d'));
// Three 30-day blocks, oldest first.
$blocks = [];
foreach ([[89, 60], [59, 30], [29, 0]] as [$from, $to]) {
    $f = $today->modify("-$from days")->format('Y-m-d');
    $t = $today->modify("-$to days")->format('Y-m-d');
    $blocks[] = ['label' => date('j M', strtotime($f)) . ' to ' . date('j M', strtotime($t)),
        'avg' => menopause_averages(array_filter($logs, fn ($l) => $l['log_date'] >= $f && $l['log_date'] <= $t))];
}
$patterns = menopause_trigger_patterns($logs);
$scores = q('SELECT * FROM menopause_scores WHERE user_id = ? ORDER BY taken_on DESC LIMIT 6', [$uid])->fetchAll();
$meds = user_medications($uid);
$hrt = hrt_medications($meds);
$others = array_filter($meds, fn ($m) => !in_array($m, $hrt, true));
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
$checks = health_checks($uid, 'menopause');
[$contraUntil] = contraception_until($user, $last);

render_header('Menopause summary', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=menopause')) ?>">&larr; Menopause</a>
</div>

<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Menopause summary</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['date_of_birth'] ? ' · born ' . h(date('j M Y', strtotime($user['date_of_birth']))) . " (age $age)" : '' ?></p>
            <p class="muted">Generated <?= h((new DateTimeImmutable('now', user_tz()))->format('j M Y')) ?> from my own records in Pillbox</p>
        </div>
    </header>

    <section>
        <h2>Overview</h2>
        <ul>
            <li>Stage (my understanding): <?= h(MENO_STAGES[$profile['stage'] ?? ''] ?? 'not recorded') ?><?= $profile['cause'] && $profile['cause'] !== 'natural' ? ' · ' . h(mb_strtolower(MENO_CAUSES[$profile['cause']])) : '' ?></li>
            <li>Last period: <?= $last ? h($last->format('j M Y')) . ' (' . months_between($last, $today) . ' months ago)' : 'none logged' ?></li>
            <?php if ($profile['hrt_started']): ?><li>Started or last changed HRT: <?= h(date('j M Y', strtotime($profile['hrt_started']))) ?></li><?php endif; ?>
            <?php if ($contraUntil): ?><li>Contraception needed until about <?= h($contraUntil->format('M Y')) ?> (by NHS guidance on age and last period)</li><?php endif; ?>
        </ul>
    </section>

    <section>
        <h2>Periods and bleeding (last 12 months)</h2>
        <?php if (!$periods): ?><p class="muted">None logged.</p><?php else: ?>
        <p><?php foreach ($periods as $i => $p): ?><?= $i ? ' · ' : '' ?><?= h(date('j M', strtotime($p['start_date']))) ?><?= $p['flow'] ? ' (' . h(mb_strtolower(CYCLE_FLOW[$p['flow']] ?? $p['flow'])) . ')' : '' ?><?php endforeach; ?></p>
        <?php endif; ?>
    </section>

    <section>
        <h2>Symptoms (last 3 months)</h2>
        <?php if (!$logs): ?><p class="muted">No diary entries.</p><?php else: ?>
        <table>
            <thead><tr><th>0 none, 1 mild, 2 moderate, 3 severe (average)</th><?php foreach ($blocks as $b): ?><th class="num"><?= h($b['label']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <tr><td>Days logged</td><?php foreach ($blocks as $b): ?><td class="num"><?= (int) $b['avg']['days'] ?></td><?php endforeach; ?></tr>
                <tr><td><strong>Hot flushes a day</strong></td><?php foreach ($blocks as $b): ?><td class="num"><?= $b['avg']['flushes'] !== null ? h(number_format($b['avg']['flushes'], 1)) : '–' ?></td><?php endforeach; ?></tr>
                <?php foreach (MENO_SYMPTOMS as $key => $label):
                    if (!array_filter($blocks, fn ($b) => isset($b['avg']['symptoms'][$key]))) { continue; } ?>
                <tr><td><?= h($label) ?></td><?php foreach ($blocks as $b): ?><td class="num"><?= isset($b['avg']['symptoms'][$key]) ? h(number_format($b['avg']['symptoms'][$key], 1)) : '–' ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php foreach ($patterns as [$label, $with, $without, $n]): ?>
        <p class="small">Hot flushes on days with <?= h(mb_strtolower($label)) ?>: <?= h(number_format($with, 1)) ?> on average, vs <?= h(number_format($without, 1)) ?> on other days (<?= $n ?> days).</p>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ($scores): ?>
    <section>
        <h2>Greene Climacteric Scale</h2>
        <table>
            <thead><tr><th>Date</th><th class="num">Total (of 63)</th><th class="num">Psychological (33)</th><th class="num">Physical (21)</th><th class="num">Vasomotor (6)</th><th class="num">Sexual (3)</th></tr></thead>
            <tbody><?php foreach ($scores as $s): ?>
                <tr><td><?= h(date('j M Y', strtotime($s['taken_on']))) ?></td><td class="num"><?= (int) $s['total'] ?></td><td class="num"><?= (int) $s['psych'] ?></td><td class="num"><?= (int) $s['somatic'] ?></td><td class="num"><?= (int) $s['vasomotor'] ?></td><td class="num"><?= (int) $s['sexual'] ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </section>
    <?php endif; ?>

    <section>
        <h2>Treatment</h2>
        <?php if (!$hrt): ?><p class="muted">No HRT or menopause treatment recorded.</p><?php else: ?>
        <ul><?php foreach ($hrt as $m): ?>
            <li><strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> · <?= h(implode('; ', schedule_lines($schedules[$m['id']] ?? [], $m['dose_unit'])) ?: ($m['as_needed'] ? 'as needed' : '')) ?>
                <span class="muted">· since <?= h(date('j M Y', strtotime($m['start_date']))) ?></span></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($others): ?><p class="small">Other current medicines and supplements: <?= h(implode(', ', array_map(fn ($m) => trim($m['name'] . ' ' . $m['strength']), $others))) ?>.</p><?php endif; ?>
    </section>

    <?php if ($checks): ?>
    <section>
        <h2>Checks</h2>
        <ul><?php foreach ($checks as $c): [$due] = check_next_due($c, $user, $today); ?>
            <li><?= h($c['label']) ?>: <?= $c['last_done'] ? 'last ' . h(date('j M Y', strtotime($c['last_done']))) : 'not recorded' ?><?= $due ? ' · next ' . h($due->format('j M Y')) : '' ?></li>
        <?php endforeach; ?></ul>
    </section>
    <?php endif; ?>

    <section>
        <h2>Questions and notes</h2>
        <div class="write-lines"></div>
    </section>
</article>
<?php render_footer();
