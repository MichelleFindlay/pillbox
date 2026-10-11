<?php
/*
 * Premenstrual symptoms: a printable summary for a GP or specialist: daily DRSP ratings by
 * cycle day over the last cycles, the week before each bleed compared with days 4 to 10, each
 * symptom's average in both, treatments tried, and lifestyle patterns.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
if (!share_area_allowed('womens')) {
    redirect('care.php');
}

$logs = pmdd_logs($uid, $today->modify('-9 months')->format('Y-m-d'), $today->format('Y-m-d'));
$comparisons = pmdd_cycle_comparisons($uid, $logs);
$treatments = pmdd_treatments($uid, $comparisons);
$lifestyle = pmdd_lifestyle_patterns($logs);
$anchors = cycle_anchors($uid);
$cycleLength = average_cycle_length($anchors);
// Each item's average in the week before a bleed and on days 4 to 10, over the completed cycles.
$itemPre = $itemPost = [];
foreach ($comparisons as $c) {
    $next = new DateTimeImmutable($c['next']);
    $start = new DateTimeImmutable($c['start']);
    for ($d = $next->modify('-7 days'); $d < $next; $d = $d->modify('+1 day')) {
        foreach ($logs[$d->format('Y-m-d')]['scores'] ?? [] as $n => $v) {
            $itemPre[$n][] = $v;
        }
    }
    for ($d = $start->modify('+3 days'); $d <= $start->modify('+9 days') && $d < $next; $d = $d->modify('+1 day')) {
        foreach ($logs[$d->format('Y-m-d')]['scores'] ?? [] as $n => $v) {
            $itemPost[$n][] = $v;
        }
    }
}

render_header('Premenstrual symptoms summary', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=womens#pmdd')) ?>">&larr; Back</a>
</div>

<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Premenstrual symptoms: daily ratings</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['pronouns'] ? ' (' . h($user['pronouns']) . ')' : '' ?><?= $user['date_of_birth'] ? ' · born ' . h(date('j M Y', strtotime($user['date_of_birth']))) : '' ?></p>
            <p class="muted">Rated prospectively each day with the DRSP (Daily Record of Severity of Problems; 1 not at all to 6 extreme). Generated <?= h($today->format('j M Y')) ?>.</p>
        </div>
    </header>

    <section>
        <h2>Overview</h2>
        <ul>
            <li><?= count($logs) ?> days rated over the last 9 months; <?= count(array_filter($comparisons, fn ($c) => $c['pattern'] !== 'unknown')) ?> complete cycle<?= count(array_filter($comparisons, fn ($c) => $c['pattern'] !== 'unknown')) === 1 ? '' : 's' ?> with enough ratings to compare</li>
            <li>Average cycle length: <?= $cycleLength ?> days<?= q('SELECT COUNT(*) FROM pmdd_markers WHERE user_id = ?', [$uid])->fetchColumn() ? ' (some cycles marked without a bleed)' : '' ?></li>
        </ul>
    </section>

    <section>
        <h2>Severity by cycle day</h2>
        <?= pmdd_heatmap($uid, $logs, $today, 3) ?>
        <p class="muted small">Each row is a cycle, from day 1 of bleeding (or a marked day 1). Shaded: the 2 weeks before the next bleed. Darker: more severe (average of the symptom ratings that day).</p>
    </section>

    <?php if ($comparisons): ?>
    <section>
        <h2>Week before a bleed vs days 4 to 10</h2>
        <table>
            <thead><tr><th>Cycle</th><th class="num">Week before</th><th class="num">Days 4–10</th><th class="num">Change</th><th>Days rated</th></tr></thead>
            <tbody><?php foreach ($comparisons as $c): ?>
                <tr><td><?= h(date('j M Y', strtotime($c['start']))) ?></td><td class="num"><?= $c['pre'] !== null ? h(number_format($c['pre'], 1)) : '–' ?></td>
                    <td class="num"><?= $c['post'] !== null ? h(number_format($c['post'], 1)) : '–' ?></td><td class="num"><?= $c['change'] !== null ? h(sprintf('%+d%%', (int) round($c['change']))) : '–' ?></td>
                    <td><?= $c['pre_n'] ?> / <?= $c['post_n'] ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </section>

    <section>
        <h2>Each item: week before a bleed vs days 4 to 10 (average)</h2>
        <table>
            <thead><tr><th>Item</th><th class="num">Week before</th><th class="num">Days 4–10</th></tr></thead>
            <tbody><?php foreach (DRSP_ITEMS as $n => [$text]): if (empty($itemPre[$n]) && empty($itemPost[$n])) { continue; } ?>
                <tr><td><?= h($text) ?></td><td class="num"><?= !empty($itemPre[$n]) ? h(number_format(array_sum($itemPre[$n]) / count($itemPre[$n]), 1)) : '–' ?></td>
                    <td class="num"><?= !empty($itemPost[$n]) ? h(number_format(array_sum($itemPost[$n]) / count($itemPost[$n]), 1)) : '–' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php $tsh = q("SELECT * FROM lab_results WHERE user_id = ? AND test = 'tsh' ORDER BY taken_at DESC LIMIT 1", [$uid])->fetch(); ?>
    <section>
        <h2>Thyroid</h2>
        <p class="small"><?= $tsh ? 'Latest TSH: ' . h(num($tsh['value'])) . ' ' . h($tsh['unit']) . ' (' . h(local_time($tsh['taken_at'], 'j M Y')) . ').'
            : 'No TSH result recorded. Thyroid problems can cause low mood and tiredness, so a TSH test may be worth considering.' ?></p>
    </section>

    <section>
        <h2>Treatments</h2>
        <?php if (!$treatments): ?><p class="muted">None recorded.</p><?php else: ?>
        <ul><?php foreach ($treatments as $t): $m = $t['med']; ?>
            <li><strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> · from <?= h(date('j M Y', strtotime($m['start_date']))) ?><?= $m['end_date'] ? ' to ' . h(date('j M Y', strtotime($m['end_date']))) : '' ?>
                <?= $t['before'] !== null && $t['after'] !== null ? ' · week-before average ' . h(number_format($t['before'], 1)) . ' before, ' . h(number_format($t['after'], 1)) . ' after' : '' ?></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php foreach ($lifestyle as [$label, $with, $without, $n]): ?>
        <p class="small">Days marked "<?= h(mb_strtolower($label)) ?>": average <?= h(number_format($with, 1)) ?> vs <?= h(number_format($without, 1)) ?> (<?= $n ?> days).</p>
        <?php endforeach; ?>
    </section>

    <section>
        <h2>Questions and notes</h2>
        <div class="write-lines"></div>
    </section>
</article>
<?php render_footer();
