<?php
/*
 * Migraine and headaches: a one-page summary for a GP or neurologist: headache, migraine and
 * acute-medicine days month by month, overuse warnings, typical features, triggers, how well
 * acute medicines work, preventives tried (with before and after), the CGRP evidence, and
 * menstrual migraine. Printable.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
if (!share_area_allowed('migraine')) {
    redirect('care.php');
}
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $first = $today->modify('first day of this month')->modify("-$i months");
    $months[$first->format('M Y')] = headache_days($uid, $first, min($first->modify('last day of this month'), $today));
}
$episodes = headache_episodes($uid, gmdate('Y-m-d H:i:s', time() - 182 * 86400));
$n = count($episodes);
$count = function (string $field) use ($episodes) {
    $c = [];
    foreach ($episodes as $e) {
        foreach ($e[$field] as $k) {
            $c[$k] = ($c[$k] ?? 0) + 1;
        }
    }
    arsort($c);
    return $c;
};
$relief = [];
foreach ($episodes as $e) {
    foreach ($e['meds'] as $m) {
        if ($m['helped'] !== null) {
            $relief[$m['name']][] = (int) $m['helped'];
        }
    }
}
$preventives = array_filter(user_medications($uid, false), fn ($m) => preg_match(PREVENTIVE_PATTERN, $m['name']));
$migraineDays = [];
foreach (q('SELECT started_at FROM headache_episodes WHERE user_id = ? AND migraine = 1 AND started_at >= ?', [$uid, gmdate('Y-m-d H:i:s', time() - 365 * 86400)]) as $e) {
    $migraineDays[local_time($e['started_at'], 'Y-m-d')] = true;
}
$mm = menstrual_migraine($uid, $migraineDays);
$full = array_slice($months, -4, 3);
$avgMigraine = round(array_sum(array_column($full, 'migraine')) / 3, 1);

render_header('Migraine summary', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=migraine')) ?>">&larr; Migraine</a>
</div>
<article class="report">
    <header class="report-head">
        <?= logo_svg(56) ?>
        <div>
            <h1>Migraine and headache summary</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['pronouns'] ? ' (' . h($user['pronouns']) . ')' : '' ?><?= $user['date_of_birth'] ? ' · born ' . h(date('j M Y', strtotime($user['date_of_birth']))) : '' ?></p>
            <p class="muted">From a daily headache diary. Generated <?= h($today->format('j M Y')) ?>.</p>
        </div>
    </header>
    <section>
        <h2>Month by month</h2>
        <table><thead><tr><th></th><th class="num">Headache days</th><th class="num">Migraine days</th><th class="num">Acute medicine days</th><th class="num">Simple painkillers</th><th class="num">Triptans</th><th class="num">Opioid or combination</th></tr></thead><tbody>
            <?php foreach ($months as $label => $d): ?><tr><td><?= h($label) ?></td><td class="num"><?= $d['headache'] ?></td><td class="num"><?= $d['migraine'] ?></td><td class="num"><?= $d['acute'] ?></td>
                <td class="num"><?= $d['by_class']['simple'] ?></td><td class="num"><?= $d['by_class']['triptan'] ?></td><td class="num"><?= $d['by_class']['opioid'] + $d['by_class']['combination'] ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php foreach (overuse_warnings(headache_days($uid, $today->modify('-29 days'), $today)) as [, $text]): ?><p class="small"><strong><?= h($text) ?></strong></p><?php endforeach; ?>
    </section>
    <?php if ($n): $sym = $count('symptoms'); $trig = $count('triggers'); ?>
    <section>
        <h2>Typical features (<?= $n ?> headaches, 6 months)</h2>
        <p class="small"><?= count(array_filter($episodes, fn ($e) => $e['migraine'])) ?> recorded as migraine, <?= count(array_filter($episodes, fn ($e) => $e['aura'])) ?> with aura,
            <?= count(array_filter($episodes, fn ($e) => (int) $e['severity'] === 3)) ?> severe.
            <?= $sym ? 'Symptoms: ' . h(implode(', ', array_map(fn ($k, $c) => mb_strtolower(HEADACHE_SYMPTOMS[$k] ?? $k) . " ($c)", array_keys($sym), $sym))) . '.' : '' ?></p>
        <?php if ($trig): ?><p class="small">Possible triggers noted: <?= h(implode(', ', array_map(fn ($k, $c) => mb_strtolower(HEADACHE_TRIGGERS[$k] ?? $k) . " ($c)", array_keys($trig), $trig))) ?>.</p><?php endif; ?>
        <?php if ($mm): ?><p class="small"><?= $mm['in'] ?> of <?= $mm['total'] ?> migraine days (last year) were from 2 days before to 3 days after a period started.</p><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($relief): ?>
    <section>
        <h2>Acute treatment: how well it helped</h2>
        <ul class="small"><?php foreach ($relief as $name => $vals): ?><li><?= h($name) ?>: <?= count($vals) ?> times, <?= count(array_filter($vals, fn ($v) => $v >= 2)) ?> helped a lot or took it away</li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
    <section>
        <h2>Preventive treatment</h2>
        <?php if (!$preventives): ?><p class="muted">None recorded.</p><?php endif; ?>
        <ul class="small"><?php foreach ($preventives as $m): $start = new DateTimeImmutable($m['start_date'], $today->getTimezone()); $before = headache_days($uid, $start->modify('-90 days'), $start->modify('-1 day')); ?>
            <li><?= h($m['name']) ?> <?= h((string) $m['strength']) ?>: from <?= h($start->format('j M Y')) ?><?= $m['end_date'] ? ' to ' . h(date('j M Y', strtotime($m['end_date']))) : '' ?>; <?= $before['migraine'] ?> migraine days in the 3 months before starting</li>
        <?php endforeach; ?></ul>
        <p class="small">For CGRP medicines: about <?= h(num($avgMigraine)) ?> migraine days a month over the last 3 full months; <?= count(array_filter($preventives, fn ($m) => !preg_match(CGRP_PATTERN, $m['name']))) ?> preventive treatments recorded.</p>
    </section>
    <section>
        <h2>Questions and notes</h2>
        <div class="write-lines"></div>
    </section>
</article>
<?php render_footer();
