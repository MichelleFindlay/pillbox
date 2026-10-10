<?php
/*
 * Mind summary: a printable report of the last 4 to 12 weeks to take to appointments.
 * Notes, journal entries and thought records are left out unless the user ticks them.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$today = local_today();

$weeks = in_array(get('weeks'), ['4', '8', '12'], true) ? (int) get('weeks') : 4;
$from = $today->modify('-' . ($weeks * 7 - 1) . ' days');
$prevFrom = $from->modify('-' . ($weeks * 7) . ' days');
$withNotes = get('notes') === '1';
$withJournal = get('journal') === '1';
$utc = new DateTimeZone('UTC');
$fromUtc = $from->setTimezone($utc)->format('Y-m-d H:i:s');
$toUtc = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

$checkins = mind_checkins($uid, $from->format('Y-m-d'), $today->format('Y-m-d'));
$daily = mind_daily($checkins);
$prevDaily = mind_daily(mind_checkins($uid, $prevFrom->format('Y-m-d'), $from->modify('-1 day')->format('Y-m-d')));
$mean = function (array $days, string $f): ?float {
    $v = array_filter(array_column($days, $f), fn ($x) => $x !== null);
    return $v ? array_sum($v) / count($v) : null;
};
$tags = [];
foreach ($checkins as $c) {
    foreach (array_filter(explode(',', (string) $c['tags'])) as $t) {
        $tags[$t] = ($tags[$t] ?? 0) + 1;
    }
}
arsort($tags);
$triggers = array_count_values(array_map('mb_strtolower', array_filter(array_column($checkins, 'triggers'))));
arsort($triggers);
$scores = q('SELECT * FROM mind_questionnaires WHERE user_id = ? AND taken_at >= ? AND taken_at < ? ORDER BY taken_at', [$uid, $fromUtc, $toUtc])->fetchAll();
$sleep = q("SELECT value1 FROM health_readings WHERE user_id = ? AND metric = 'sleep' AND recorded_at >= ? AND recorded_at < ?", [$uid, $fromUtc, $toUtc])->fetchAll(PDO::FETCH_COLUMN);
$medChanges = q('SELECT c.*, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id
                 WHERE c.user_id = ? AND c.effective_date BETWEEN ? AND ? ORDER BY c.effective_date',
    [$uid, $from->format('Y-m-d'), $today->format('Y-m-d')])->fetchAll();
$sideEffects = q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'symptom' AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at",
    [$uid, $fromUtc, $toUtc])->fetchAll();
$journal = $withJournal ? q('SELECT * FROM mind_journal WHERE user_id = ? AND created_at >= ? AND created_at < ? ORDER BY created_at', [$uid, $fromUtc, $toUtc])->fetchAll() : [];
$insights = mind_insights($user, $today);
$ratings = symptom_ratings(array_column($checkins, 'id'));
$symptomStats = [];
foreach ($ratings as $perCheckin) {
    foreach ($perCheckin as $sid => $v) {
        $symptomStats[$sid][] = $v;
    }
}
$symptomNames = array_column(mind_symptoms($uid, false), 'name', 'id');
$selfcareDays = q('SELECT log_date, COUNT(*) AS n FROM mind_selfcare WHERE user_id = ? AND log_date BETWEEN ? AND ? GROUP BY log_date',
    [$uid, $from->format('Y-m-d'), $today->format('Y-m-d')])->fetchAll(PDO::FETCH_KEY_PAIR);
$activities = q("SELECT * FROM mind_activities WHERE user_id = ? AND planned_for BETWEEN ? AND ? AND status <> 'planned' ORDER BY planned_for",
    [$uid, $from->format('Y-m-d'), $today->format('Y-m-d')])->fetchAll();
$doneActs = array_filter($activities, fn ($a) => $a['status'] === 'done');
$in = implode(',', array_fill(0, count(MIND_APPOINTMENT_KINDS), '?'));
$sessions = q("SELECT * FROM appointments WHERE user_id = ? AND kind IN ($in) AND starts_at >= ? AND starts_at < ? ORDER BY starts_at",
    [$uid, ...MIND_APPOINTMENT_KINDS, $fromUtc, $toUtc])->fetchAll();
$actions = array_filter(mind_notes($uid, 'action'), fn ($n) => !$n['done'] || $n['created_at'] >= $fromUtc);
$medNotes = array_reverse(med_notes($uid, null, $fromUtc));
$tz = $today->getTimezone();
$points = function (string $field) use ($daily, $tz): array {
    $out = [];
    foreach ($daily as $date => $d) {
        if ($d[$field] !== null) {
            $out[] = [(new DateTimeImmutable($date . ' 12:00', $tz))->getTimestamp(), round($d[$field], 1)];
        }
    }
    return $out;
};
$fmt = fn (?float $v, int $dp = 1) => $v === null ? '–' : number_format($v, $dp);
$change = function (?float $now, ?float $before) use ($weeks) {
    if ($now === null || $before === null || abs($now - $before) < 0.05) {
        return '';
    }
    return ($now > $before ? 'up ' : 'down ') . number_format(abs($now - $before), 1) . ' on the previous ' . $weeks . ' weeks';
};

render_header('Mind summary', 'mind');
?>
<form method="get" class="card filters no-print">
    <label>Period
        <select name="weeks"><?php foreach ([4, 8, 12] as $w): ?><option value="<?= $w ?>"<?= selected($weeks === $w) ?>>Last <?= $w ?> weeks</option><?php endforeach; ?></select></label>
    <label class="check"><input type="checkbox" name="notes" value="1"<?= checked($withNotes) ?>> Include check-in notes</label>
    <label class="check"><input type="checkbox" name="journal" value="1"<?= checked($withJournal) ?>> Include journal and thought records</label>
    <button class="btn" type="submit">Update</button>
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
</form>

<article class="report">
    <header class="report-head">
        <img src="<?= h(asset('assets/logo.svg')) ?>" alt="" width="56" height="56">
        <div>
            <h1>Mood and wellbeing summary</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['date_of_birth'] ? ' · born ' . h((new DateTimeImmutable($user['date_of_birth']))->format('j M Y')) : '' ?></p>
            <p class="muted"><?= h($from->format('j M Y')) ?> to <?= h($today->format('j M Y')) ?> (<?= $weeks ?> weeks) · generated <?= h((new DateTimeImmutable('now', user_tz()))->format('j M Y H:i')) ?></p>
        </div>
    </header>

    <section>
        <h2>Check-ins</h2>
        <?php if (!$daily): ?><p class="muted">No check-ins in this period.</p><?php else: ?>
        <p class="small"><?= count($checkins) ?> check-ins on <?= count($daily) ?> of <?= $weeks * 7 ?> days. Daily averages:</p>
        <div class="tiles">
            <?php foreach (['mood' => ['Mood', 5], 'energy' => ['Energy', 5], 'motivation' => ['Motivation', 5], 'anxiety' => ['Anxiety or stress', 10]] as $f => [$label, $max]):
                $now = $mean($daily, $f); if ($now === null) { continue; } ?>
            <div class="tile"><span class="tile-label"><?= h($label) ?></span><span class="tile-value"><?= $fmt($now) ?> <span class="muted small">/ <?= $max ?></span></span>
                <span class="tile-meta"><?= h($change($now, $mean($prevDaily, $f))) ?: 'range ' . $fmt(min(array_filter(array_column($daily, $f), fn ($x) => $x !== null)), 0) . ' to ' . $fmt(max(array_filter(array_column($daily, $f), fn ($x) => $x !== null)), 0) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?= svg_line_chart([
            ['label' => 'Mood', 'unit' => '', 'points' => $points('mood')],
            ['label' => 'Energy', 'unit' => '', 'points' => $points('energy')],
            ['label' => 'Motivation', 'unit' => '', 'points' => $points('motivation')],
        ], $from->getTimestamp(), $today->modify('+1 day')->getTimestamp(), 'Mood, energy and motivation') ?>
        <?php if ($points('anxiety')): ?>
        <?= svg_line_chart([['label' => 'Anxiety', 'unit' => '', 'points' => $points('anxiety')]], $from->getTimestamp(), $today->modify('+1 day')->getTimestamp(), 'Anxiety') ?>
        <?php endif; ?>
        <?php if ($tags): ?><p class="small"><strong>Most used words:</strong> <?= h(implode(', ', array_map(fn ($t, $n) => "$t ($n)", array_keys(array_slice($tags, 0, 8)), array_slice($tags, 0, 8)))) ?></p><?php endif; ?>
        <?php if ($triggers): ?><p class="small"><strong>Triggers mentioned:</strong> <?= h(implode('; ', array_map(fn ($t, $n) => $n > 1 ? "$t ($n)" : $t, array_keys(array_slice($triggers, 0, 15)), array_slice($triggers, 0, 15)))) ?></p><?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($symptomStats || $selfcareDays || $activities): ?>
    <section>
        <h2>Symptoms, self-care and activities</h2>
        <?php if ($symptomStats): ?>
        <table class="compact">
            <thead><tr><th>Symptom</th><th class="num">Average</th><th class="num">Highest</th><th class="num">Ratings</th></tr></thead>
            <tbody><?php foreach ($symptomStats as $sid => $vals): ?>
                <tr><td><?= h($symptomNames[$sid] ?? 'Symptom') ?></td><td class="num"><?= number_format(array_sum($vals) / count($vals), 1) ?> / 10</td><td class="num"><?= max($vals) ?></td><td class="num"><?= count($vals) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
        <?php if ($selfcareDays): ?>
        <p class="small"><strong>Self-care basics:</strong> ticked off on <?= count($selfcareDays) ?> of <?= $weeks * 7 ?> days, averaging <?= number_format(array_sum($selfcareDays) / count($selfcareDays), 1) ?>
            of <?= count(selfcare_items($user)) ?> on those days.</p>
        <?php endif; ?>
        <?php if ($activities): $avgOf = fn ($f) => ($v = array_filter(array_column($doneActs, $f), fn ($x) => $x !== null)) ? number_format(array_sum($v) / count($v), 1) : '–'; ?>
        <p class="small"><strong>Planned activities:</strong> <?= count($doneActs) ?> of <?= count($activities) ?> done; average enjoyment <?= $avgOf('enjoyment') ?>, achievement <?= $avgOf('achievement') ?> out of 10.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($sessions || $actions): ?>
    <section>
        <h2>Therapy and reviews</h2>
        <?php foreach ($sessions as $s): ?>
        <p class="small"><strong><?= h(local_time($s['starts_at'], 'j M Y')) ?></strong> <?= h(appointment_title($s)) ?><?= $s['with_whom'] ? ' with ' . h($s['with_whom']) : '' ?>
            <?= $s['outcome_notes'] ? '<br>' . nl2br(h($s['outcome_notes'])) : '' ?></p>
        <?php endforeach; ?>
        <?php if ($actions): ?>
        <h3>Actions agreed</h3>
        <ul><?php foreach ($actions as $n): ?><li class="small"><?= h($n['text']) ?> <span class="muted">(<?= $n['done'] ? 'done' : 'in progress' ?>)</span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($insights): ?>
    <section>
        <h2>Patterns</h2>
        <ul class="insights"><?php foreach ($insights as $i): ?><li><?= h($i) ?></li><?php endforeach; ?></ul>
        <p class="muted small">From the last 90 days of check-ins. Patterns, not causes.</p>
    </section>
    <?php endif; ?>

    <?php if ($scores): ?>
    <section>
        <h2>Questionnaires</h2>
        <table class="compact">
            <thead><tr><th>Date</th><th>Questionnaire</th><th class="num">Score</th><th>Band</th><th>Difficulty</th></tr></thead>
            <tbody>
            <?php foreach ($scores as $s): ?>
                <tr><td><?= h(local_time($s['taken_at'], 'j M Y')) ?></td><td><?= h(QUESTIONNAIRES[$s['kind']]['title']) ?></td>
                    <td class="num"><strong><?= (int) $s['score'] ?></strong> / <?= QUESTIONNAIRES[$s['kind']]['max'] ?></td>
                    <td><?= h(questionnaire_band($s['kind'], (int) $s['score'])) ?></td>
                    <td class="small"><?= $s['difficulty'] !== null ? h(QUESTIONNAIRE_DIFFICULTY[(int) $s['difficulty']]) : '–' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($sleep): ?>
    <section>
        <h2>Sleep</h2>
        <p class="small"><?= count($sleep) ?> nights recorded, averaging <?= h(format_hours(array_sum(array_map('floatval', $sleep)) / count($sleep))) ?>;
            <?= count(array_filter($sleep, fn ($x) => (float) $x < 6)) ?> under 6 hours.</p>
    </section>
    <?php endif; ?>

    <?php if ($medChanges || $sideEffects || $medNotes): ?>
    <section>
        <h2>Medication</h2>
        <?php if ($medChanges): ?>
        <h3>Changes in this period</h3>
        <ul class="plain"><?php foreach ($medChanges as $m): ?>
            <li class="logline"><span><strong><?= h(date('j M', strtotime($m['effective_date']))) ?></strong> <?= h($m['name']) ?>: <?= h($m['summary']) ?><?= $m['reason'] ? ' <span class="muted small">(' . h($m['reason']) . ')</span>' : '' ?></span></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($medNotes): ?>
        <h3>Medication notes</h3>
        <ul class="plain"><?php foreach ($medNotes as $n): ?>
            <li class="logline"><span><strong><?= h(local_time($n['created_at'], 'j M')) ?></strong> <?= h($n['name']) ?>
                (<?= $n['kind'] === 'side_effect' ? 'side effect' . ($n['severity'] !== null ? ', ' . (int) $n['severity'] . '/10' : '') : 'how I feel' ?>): <?= h($n['note']) ?></span></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($sideEffects): ?>
        <h3>Symptoms and side effects logged</h3>
        <ul class="plain"><?php foreach ($sideEffects as $s): ?>
            <li class="logline"><span><strong><?= h(local_time($s['recorded_at'], 'j M')) ?></strong> <?= h($s['notes'] ?: 'Symptom') ?> <span class="muted small">severity <?= h(num($s['value1'])) ?>/10</span></span></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($withNotes && ($noted = array_filter($checkins, fn ($c) => $c['note']))): ?>
    <section>
        <h2>Check-in notes</h2>
        <?php foreach (array_reverse($noted) as $c): ?>
        <p class="small"><strong><?= h(local_time($c['logged_at'], 'D j M, H:i')) ?></strong> (<?= h(MOODS[$c['mood']][1]) ?>): <?= nl2br(h($c['note'])) ?></p>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($journal): ?>
    <section>
        <h2>Journal and thought records</h2>
        <?php foreach ($journal as $e): ?>
        <div class="journal-print">
            <h3><?= h(local_time($e['created_at'], 'D j M Y')) ?><?= $e['kind'] === 'thought' ? ': thought record' : '' ?></h3>
            <?php if ($e['kind'] === 'thought'): $f = json_decode((string) $e['fields'], true) ?: []; ?>
                <?php foreach (THOUGHT_FIELDS as $k => [$label]): if (empty($f[$k])) { continue; } ?>
                <p class="small"><strong><?= h($label) ?>:</strong> <?= nl2br(h($f[$k])) ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if ($e['prompt']): ?><p class="small muted"><?= h($e['prompt']) ?></p><?php endif; ?>
                <p class="small"><?= nl2br(h($e['body'])) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</article>
<?php render_footer();
