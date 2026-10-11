<?php
declare(strict_types=1);

/*
 * Premenstrual symptoms (PMS, PMDD and PME): a mode of cycle tracking in Care > Periods and
 * contraception, for anyone with ovaries and a cycle (body inventory permitting).
 * - Daily ratings with the DRSP (Daily Record of Severity of Problems), or a quick check-in.
 * - Cycle phases from logged bleeds, or from "day 1" markers when there's no bleed (on
 *   testosterone, after a hysterectomy with ovaries kept, irregular cycles).
 * - A chart of severity by cycle day with cycles stacked, and the week before a bleed compared
 *   with the week after (a PMDD-like pattern clears after bleeding starts; in PME, ongoing
 *   symptoms get worse before it). Patterns to show a GP, never a diagnosis.
 * - Luteal-only medicine schedules (medication_schedules.cycle_phase), treatments before and
 *   after, lifestyle patterns, an optional heads-up before harder days, and gentle links to the
 *   safety plan and crisis lines on the Help page.
 */

// DRSP items in plain, gender-free wording: [text, group, in the quick check-in].
const DRSP_ITEMS = [
    1  => ['Felt depressed, sad, down or blue', 'Mood', true],
    2  => ['Felt hopeless', 'Mood', true],
    3  => ['Felt worthless or guilty', 'Mood', false],
    4  => ['Felt anxious, tense, keyed up or on edge', 'Mood', true],
    5  => ['Had mood swings (e.g. suddenly sad or tearful)', 'Mood', true],
    6  => ['Was more sensitive to rejection, or my feelings were easily hurt', 'Mood', false],
    7  => ['Felt angry or irritable', 'Mood', true],
    8  => ['Had conflicts or problems with people', 'Mood', false],
    9  => ['Had less interest in usual activities (work, study, friends, hobbies)', 'Mood', false],
    10 => ['Had difficulty concentrating', 'Thinking', false],
    11 => ['Felt tired, lethargic or lacking in energy', 'Physical', false],
    12 => ['Had increased appetite or overate', 'Physical', false],
    13 => ['Had cravings for specific foods', 'Physical', false],
    14 => ['Slept more, took naps, or found it hard to get up', 'Physical', false],
    15 => ['Had trouble getting to sleep or staying asleep', 'Physical', false],
    16 => ['Felt overwhelmed or that I could not cope', 'Thinking', true],
    17 => ['Felt out of control', 'Thinking', false],
    18 => ['Had breast or chest tenderness', 'Physical', false],
    19 => ['Felt bloated, had swelling or weight gain', 'Physical', false],
    20 => ['Had a headache', 'Physical', false],
    21 => ['Had joint or muscle pain', 'Physical', false],
    22 => ['Problems made me less productive at work, study, home or my daily routine', 'Impact', true],
    23 => ['Problems got in the way of hobbies or social activities', 'Impact', false],
    24 => ['Problems got in the way of my relationships', 'Impact', false],
];
const DRSP_SCALE = [1 => 'Not at all', 2 => 'Minimal', 3 => 'Mild', 4 => 'Moderate', 5 => 'Severe', 6 => 'Extreme'];
// Items that, rated severe or extreme, bring up the safety plan and support (gently, once a day).
const DRSP_SAFETY_ITEMS = [2, 16, 17];

const PMDD_LIFESTYLE = ['exercise' => 'Exercised', 'caffeine' => 'Caffeine (2 or more drinks)', 'alcohol' => 'Alcohol', 'short_sleep' => 'Under 6 hours\' sleep'];

// One-tap treatment set-ups (open the medication form filled in).
const PMDD_PRESETS = [
    'ssri_luteal' => ['label' => 'SSRI, only before a period', 'name' => 'SSRI (e.g. sertraline or fluoxetine)', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '08:00',
        'phase' => 'luteal', 'instructions' => 'Only in the 2 weeks before a period (the luteal phase), as prescribed'],
    'ssri_daily'  => ['label' => 'SSRI, every day', 'name' => 'SSRI (e.g. sertraline or fluoxetine)', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '08:00'],
    'pill'        => ['label' => 'Combined pill, taken continuously', 'name' => 'Combined contraceptive pill', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '08:00',
        'instructions' => 'Continuously, without a break, or as prescribed'],
    'gnrh'        => ['label' => 'GnRH analogue injection', 'name' => 'GnRH analogue injection', 'form' => 'injection', 'unit' => 'injection', 'time' => '09:00', 'every' => 28,
        'instructions' => 'Usually with add-back HRT, as prescribed'],
    'addback'     => ['label' => 'Add-back HRT', 'name' => 'Add-back HRT', 'form' => 'patch', 'unit' => 'patch', 'time' => '08:00', 'days' => 9,
        'instructions' => 'Taken alongside a GnRH analogue, as prescribed'],
];

function pmdd_on(array $user): bool
{
    return !empty($user['pmdd_mode']) && body_has($user, 'ovaries') !== false;
}

/** Day 1s of cycles: bleed starts plus manual markers, oldest first. */
function cycle_anchors(int $userId): array
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $days = array_merge(
            q('SELECT start_date FROM cycle_periods WHERE user_id = ?', [$userId])->fetchAll(PDO::FETCH_COLUMN),
            q('SELECT marked_on FROM pmdd_markers WHERE user_id = ?', [$userId])->fetchAll(PDO::FETCH_COLUMN)
        );
        $days = array_values(array_unique($days));
        sort($days);
        $cache[$userId] = $days;
    }
    return $cache[$userId];
}

/** Average cycle length from recent gaps (18 to 60 days), or 28. */
function average_cycle_length(array $anchors): int
{
    $gaps = [];
    for ($i = count($anchors) - 1; $i > 0 && count($gaps) < 6; $i--) {
        $gap = (new DateTimeImmutable($anchors[$i - 1]))->diff(new DateTimeImmutable($anchors[$i]))->days;
        if ($gap >= 18 && $gap <= 60) {
            $gaps[] = $gap;
        }
    }
    return $gaps ? (int) round(array_sum($gaps) / count($gaps)) : 28;
}

/**
 * Cycle windows covering [from, to]: each with its day 1, the next day 1 (known or predicted),
 * and its luteal phase (about the 14 days before the next day 1).
 */
function cycle_windows(int $userId, string $from, string $to): array
{
    $anchors = cycle_anchors($userId);
    if (!$anchors) {
        return [];
    }
    $len = average_cycle_length($anchors);
    $out = [];
    $starts = $anchors;
    // Predicted cycles after the last known day 1.
    $last = new DateTimeImmutable(end($anchors));
    while ($last->format('Y-m-d') <= $to) {
        $last = $last->modify("+$len days");
        $starts[] = $last->format('Y-m-d');
    }
    for ($i = 0; $i + 1 < count($starts); $i++) {
        $next = new DateTimeImmutable($starts[$i + 1]);
        if ($starts[$i + 1] <= $from) {
            continue;
        }
        if ($starts[$i] > $to) {
            break;
        }
        $out[] = ['start' => $starts[$i], 'next' => $starts[$i + 1], 'predicted' => $i + 1 >= count($anchors),
            'luteal_from' => max($starts[$i], $next->modify('-14 days')->format('Y-m-d')), 'luteal_to' => $next->modify('-1 day')->format('Y-m-d')];
    }
    return $out;
}

/** Is this day in the (known or predicted) luteal phase? */
function is_luteal_day(int $userId, string $ymd): bool
{
    static $cache = [];
    $key = $userId . '|' . substr($ymd, 0, 7);
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        $month = new DateTimeImmutable(substr($ymd, 0, 7) . '-01');
        foreach (cycle_windows($userId, $month->modify('-1 month')->format('Y-m-d'), $month->modify('+2 months')->format('Y-m-d')) as $w) {
            for ($d = new DateTimeImmutable($w['luteal_from']); $d->format('Y-m-d') <= $w['luteal_to']; $d = $d->modify('+1 day')) {
                $cache[$key][$d->format('Y-m-d')] = true;
            }
        }
    }
    return isset($cache[$key][$ymd]);
}

/** Where today is: [cycle day, phase, the window] or null without any cycle data. */
function cycle_position(int $userId, DateTimeImmutable $day): ?array
{
    $ymd = $day->format('Y-m-d');
    foreach (cycle_windows($userId, $ymd, $ymd) as $w) {
        if ($w['start'] <= $ymd && $ymd < $w['next']) {
            $n = (new DateTimeImmutable($w['start']))->diff(new DateTimeImmutable($ymd))->days + 1;
            $phase = $ymd >= $w['luteal_from'] ? 'luteal' : ($n <= 5 ? 'bleed' : 'follicular');
            return [$n, $phase, $w];
        }
    }
    return null;
}

function pmdd_logs(int $userId, string $from, string $to): array
{
    $out = [];
    foreach (q('SELECT * FROM pmdd_logs WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date', [$userId, $from, $to]) as $r) {
        $r['scores'] = array_map('intval', json_decode((string) $r['scores'], true) ?: []);
        $r['lifestyle'] = array_filter(explode(',', (string) $r['lifestyle']));
        $out[$r['log_date']] = $r;
    }
    return $out;
}

/** A day's symptom score: the average of the symptom items rated (1 to 6), impact items left out. */
function drsp_day_score(array $log): ?float
{
    $vals = array_filter($log['scores'], fn ($v, $k) => $k <= 21 && $v > 0, ARRAY_FILTER_USE_BOTH);
    return $vals ? array_sum($vals) / count($vals) : null;
}

function mean_of(array $vals): ?float
{
    $vals = array_values(array_filter($vals, fn ($v) => $v !== null));
    return $vals ? array_sum($vals) / count($vals) : null;
}

/**
 * Completed cycles compared: the week before the next bleed (days -7 to -1) with days 4 to 10.
 * A rise of 30% or more is the usual sign of a premenstrual pattern; if the after-bleed week is
 * itself raised, symptoms are there all month and get worse before a bleed (like PME).
 */
function pmdd_cycle_comparisons(int $userId, array $logs, int $max = 6): array
{
    $out = [];
    $anchors = cycle_anchors($userId);
    for ($i = count($anchors) - 1; $i > 0 && count($out) < $max; $i--) {
        $start = new DateTimeImmutable($anchors[$i - 1]);
        $next = new DateTimeImmutable($anchors[$i]);
        $pre = $post = [];
        for ($d = $next->modify('-7 days'); $d < $next; $d = $d->modify('+1 day')) {
            $pre[] = isset($logs[$d->format('Y-m-d')]) ? drsp_day_score($logs[$d->format('Y-m-d')]) : null;
        }
        for ($d = $start->modify('+3 days'); $d <= $start->modify('+9 days') && $d < $next; $d = $d->modify('+1 day')) {
            $post[] = isset($logs[$d->format('Y-m-d')]) ? drsp_day_score($logs[$d->format('Y-m-d')]) : null;
        }
        $preN = count(array_filter($pre, fn ($v) => $v !== null));
        $postN = count(array_filter($post, fn ($v) => $v !== null));
        $a = mean_of($pre);
        $b = mean_of($post);
        $change = $a !== null && $b ? ($a - $b) / $b * 100 : null;
        $pattern = $preN < 3 || $postN < 3 ? 'unknown'
            : ($change >= 30 ? ($b >= 2.5 ? 'pme' : 'pmdd') : 'none');
        $out[] = ['start' => $anchors[$i - 1], 'next' => $anchors[$i], 'pre' => $a, 'post' => $b, 'change' => $change, 'pattern' => $pattern, 'pre_n' => $preN, 'post_n' => $postN];
    }
    return $out;
}

const PMDD_PATTERNS = [
    'pmdd'    => 'Symptoms rose before the bleed and settled after it (a premenstrual pattern)',
    'pme'     => 'Symptoms were there all month and got worse before the bleed (premenstrual worsening)',
    'none'    => 'No clear premenstrual rise',
    'unknown' => 'Not enough days logged in both weeks to compare',
];

/** Symptom score on days with and without each lifestyle factor (at least 4 days each). */
function pmdd_lifestyle_patterns(array $logs): array
{
    $out = [];
    foreach (PMDD_LIFESTYLE as $key => $label) {
        $with = $without = [];
        foreach ($logs as $l) {
            $s = drsp_day_score($l);
            if ($s !== null) {
                in_array($key, $l['lifestyle'], true) ? $with[] = $s : $without[] = $s;
            }
        }
        if (count($with) >= 4 && count($without) >= 4 && abs(mean_of($with) - mean_of($without)) >= 0.4) {
            $out[] = [$label, mean_of($with), mean_of($without), count($with)];
        }
    }
    return $out;
}

/** Medicines that look like premenstrual treatment, with the premenstrual week before and after starting. */
function pmdd_treatments(int $userId, array $comparisons): array
{
    $out = [];
    foreach (user_medications($userId, false) as $m) {
        if (!preg_match('/pmdd|pms|premenstrual|ssri|sertraline|fluoxetine|citalopram|escitalopram|paroxetine|combined (contraceptive )?pill|gnrh|add-back/i', $m['name'] . ' ' . $m['reason'])) {
            continue;
        }
        $before = mean_of(array_column(array_filter($comparisons, fn ($c) => $c['next'] <= $m['start_date']), 'pre'));
        $after = mean_of(array_column(array_filter($comparisons, fn ($c) => $c['start'] >= $m['start_date']), 'pre'));
        $out[] = ['med' => $m, 'before' => $before, 'after' => $after];
    }
    return $out;
}

/**
 * Severity by cycle day, cycles stacked (newest at the top), luteal phase shaded, as SVG.
 * Colours come from CSS classes (.heat-1 to .heat-6), so no inline styles.
 */
function pmdd_heatmap(int $userId, array $logs, DateTimeImmutable $today, int $cycles = 4): string
{
    $windows = array_slice(array_values(array_filter(cycle_windows($userId, $today->modify('-8 months')->format('Y-m-d'), $today->format('Y-m-d')),
        fn ($w) => $w['start'] <= $today->format('Y-m-d'))), -$cycles);
    if (!$windows) {
        return '<p class="muted small">Log a bleed (or mark day 1 of a cycle) to see your symptoms by cycle day.</p>';
    }
    $windows = array_reverse($windows);
    $maxDays = 35;
    $cell = 16;
    $labelW = 70;
    $w = $labelW + $maxDays * $cell + 4;
    $h = 22 + count($windows) * ($cell + 6);
    $svg = sprintf('<svg viewBox="0 0 %d %d" class="heatmap" role="img" aria-label="Symptom severity by cycle day">', $w, $h);
    foreach ([1, 7, 14, 21, 28, 35] as $d) {
        $svg .= sprintf('<text class="axis" x="%d" y="12" text-anchor="middle">%d</text>', $labelW + ($d - 1) * $cell + $cell / 2, $d);
    }
    foreach ($windows as $r => $win) {
        $y = 20 + $r * ($cell + 6);
        $start = new DateTimeImmutable($win['start']);
        $len = $start->diff(new DateTimeImmutable($win['next']))->days;
        $svg .= sprintf('<text class="axis" x="0" y="%d" dominant-baseline="middle">%s</text>', $y + $cell / 2, h($start->format('j M')));
        $lutealFrom = $start->diff(new DateTimeImmutable($win['luteal_from']))->days;
        $svg .= sprintf('<rect class="luteal-band" x="%d" y="%d" width="%d" height="%d"><title>Luteal phase (the 2 weeks before a bleed)%s</title></rect>',
            $labelW + $lutealFrom * $cell, $y - 2, max(0, min($len, $maxDays) - $lutealFrom) * $cell, $cell + 4, $win['predicted'] ? ', predicted' : '');
        for ($i = 0; $i < min($len, $maxDays); $i++) {
            $day = $start->modify("+$i days")->format('Y-m-d');
            if ($day > $today->format('Y-m-d')) {
                break;
            }
            $score = isset($logs[$day]) ? drsp_day_score($logs[$day]) : null;
            $svg .= sprintf('<rect class="heat heat-%s" x="%d" y="%d" width="%d" height="%d" rx="2"><title>%s, day %d: %s</title></rect>',
                $score === null ? '0' : (string) max(1, min(6, (int) round($score))), $labelW + $i * $cell + 1, $y, $cell - 2, $cell, h(date('j M', strtotime($day))), $i + 1,
                $score === null ? 'not logged' : h(number_format($score, 1) . ' (' . mb_strtolower(DRSP_SCALE[max(1, min(6, (int) round($score)))]) . ')'));
        }
    }
    return '<div class="chart">' . $svg . '</svg></div>';
}

/** Scheduled task: an optional heads-up at the start of the predicted luteal phase. */
function send_pmdd_headsups(DateTimeImmutable $now): int
{
    $sent = 0;
    foreach (q('SELECT * FROM users WHERE pmdd_mode = 1 AND pmdd_headsup = 1 AND is_active = 1 AND pending_approval = 0') as $user) {
        set_current_user($user);
        $tz = user_tz($user);
        $local = $now->setTimezone($tz);
        if ((int) $local->format('G') < 9) {
            continue;
        }
        $pos = cycle_position((int) $user['id'], $local);
        if ($pos && $pos[2]['luteal_from'] === $local->format('Y-m-d') && push_once((int) $user['id'], 'luteal|' . $local->format('Y-m-d'))) {
            $sent += push_user($user, ['title' => 'Pillbox', 'body' => "A heads-up: harder days may be coming up. Your plan is here if you need it.",
                'url' => url('care.php?area=womens#pmdd'), 'tag' => 'luteal']);
        }
    }
    set_current_user(null);
    return $sent;
}

/** Actions posted from the premenstrual sections of care.php?area=womens ('pmdd_...'); always redirects. */
function pmdd_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=womens#pmdd';
    switch (post('action')) {
        case 'pmdd_mode':
            q('UPDATE users SET pmdd_mode = ? WHERE id = ?', [post('on') === '1' ? 1 : 0, $uid]);
            redirect($back);
        case 'pmdd_headsup':
            q('UPDATE users SET pmdd_headsup = ? WHERE id = ?', [post('on') === '1' ? 1 : 0, $uid]);
            flash('success', post('on') === '1' ? "You'll get a gentle heads-up when your harder days may be starting (turn on notifications in Account settings)." : 'Heads-up turned off.');
            redirect($back);
        case 'pmdd_marker':
            $day = parse_date(post('date'));
            if ($day && $day <= $today) {
                q('INSERT IGNORE INTO pmdd_markers (user_id, marked_on) VALUES (?, ?)', [$uid, $day->format('Y-m-d')]);
                flash('success', 'Marked ' . $day->format('j M') . ' as day 1 of a cycle.');
            }
            redirect($back);
        case 'pmdd_marker_delete':
            q('DELETE FROM pmdd_markers WHERE user_id = ? AND marked_on = ?', [$uid, post('date')]);
            redirect($back);
        case 'pmdd_log':
            $day = min(parse_date(post('date')) ?: $today, $today);
            $scores = [];
            foreach (DRSP_ITEMS as $n => $_) {
                $v = (int) ($_POST['s'][$n] ?? 0);
                if ($v >= 1 && $v <= 6) {
                    $scores[$n] = $v;
                }
            }
            if (!$scores) {
                flash('error', 'Rate at least one question.');
                redirect($back . '-log');
            }
            $quick = !array_diff_key($scores, array_filter(DRSP_ITEMS, fn ($i) => $i[2])) ? 1 : 0;
            q('INSERT INTO pmdd_logs (user_id, log_date, scores, quick, lifestyle, notes) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE scores = VALUES(scores), quick = VALUES(quick), lifestyle = VALUES(lifestyle), notes = VALUES(notes)', [
                $uid, $day->format('Y-m-d'), json_encode($scores), $quick,
                implode(',', array_intersect(array_keys(PMDD_LIFESTYLE), (array) ($_POST['lifestyle'] ?? []))), trim(mb_substr(post('notes'), 0, 500)) ?: null,
            ]);
            // Very hard days: point gently to the safety plan and support, at most once a day.
            $hard = array_filter(DRSP_SAFETY_ITEMS, fn ($n) => ($scores[$n] ?? 0) >= 5);
            if ($hard && ($_SESSION['pmdd_support_shown'] ?? '') !== $today->format('Y-m-d')) {
                $_SESSION['pmdd_support_shown'] = $today->format('Y-m-d');
                $_SESSION['pmdd_support'] = true;
            } else {
                flash('success', 'Saved for ' . $day->format('j M') . '.');
            }
            redirect($back);
        case 'pmdd_log_delete':
            q('DELETE FROM pmdd_logs WHERE user_id = ? AND log_date = ?', [$uid, post('date')]);
            redirect($back . '-log');
    }
    redirect($back);
}

/** The premenstrual symptom sections at the top of care.php?area=womens. */
function pmdd_sections(array $user, DateTimeImmutable $today): void
{
    if (body_has($user, 'ovaries') === false) {
        return;
    }
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    if (!$user['pmdd_mode']) {
        if ($canEdit): ?>
<section class="card" id="pmdd">
    <h2>Premenstrual symptoms</h2>
    <p class="small">If your mood or body changes in the days before a bleed, rating how you feel each day shows whether it follows your cycle.
        It's how PMS, PMDD (premenstrual dysphoric disorder) and premenstrual worsening of other conditions are recognised, and it works without periods too.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pmdd_mode"><input type="hidden" name="on" value="1">
        <button class="btn btn-small" type="submit">Start tracking</button></form>
</section>
        <?php endif;
        return;
    }
    $todayYmd = $today->format('Y-m-d');
    $logs = pmdd_logs($uid, $today->modify('-9 months')->format('Y-m-d'), $todayYmd);
    $todayLog = $logs[$todayYmd] ?? null;
    $pos = cycle_position($uid, $today);
    $windows = cycle_windows($uid, $todayYmd, $today->modify('+60 days')->format('Y-m-d'));
    $nextLuteal = null;
    foreach ($windows as $w) {
        if ($w['luteal_from'] > $todayYmd) {
            $nextLuteal = $w['luteal_from'];
            break;
        }
    }
    $comparisons = pmdd_cycle_comparisons($uid, $logs);
    $treatments = pmdd_treatments($uid, $comparisons);
    $lifestyle = pmdd_lifestyle_patterns($logs);
    $markers = q('SELECT marked_on FROM pmdd_markers WHERE user_id = ? ORDER BY marked_on DESC LIMIT 6', [$uid])->fetchAll(PDO::FETCH_COLUMN);
    $support = !empty($_SESSION['pmdd_support']);
    unset($_SESSION['pmdd_support']);
    $phaseLabel = ['bleed' => 'bleeding days', 'follicular' => 'the first half of your cycle', 'luteal' => 'the 2 weeks before a bleed (luteal phase)'];
    ?>
<?php if ($support): ?>
<section class="card preg-call" id="pmdd-support">
    <h2>That sounds like a really hard day</h2>
    <p>You don't have to get through it alone. Your safety plan and people you can talk to are one tap away. Samaritans are there any time on <a href="tel:116123"><strong>116 123</strong></a>,
        and NHS 111 (option 2) for urgent mental health help.</p>
    <span class="inline-actions"><a class="btn btn-primary" href="<?= h(url('help.php')) ?>">Help and my safety plan</a><a class="btn" href="tel:116123">Call Samaritans</a></span>
    <p class="muted small">Your ratings were saved. If you're in danger now, call 999.</p>
</section>
<?php endif; ?>
<section class="card" id="pmdd">
    <div class="card-head">
        <h2>Premenstrual symptoms</h2>
        <a class="btn btn-small" href="<?= h(url('pmdd_summary.php')) ?>">GP summary</a>
    </div>
    <?php if ($pos): [$n, $phase, $w] = $pos; ?>
    <p><strong>Day <?= $n ?></strong> of your cycle<?= $w['predicted'] ? ' (estimated)' : '' ?>: <?= h($phaseLabel[$phase]) ?>.
        <?php if ($phase !== 'luteal' && $nextLuteal): ?>Harder days, if you have them, may start around <strong><?= h(date('D j M', strtotime($nextLuteal))) ?></strong>.<?php endif; ?></p>
    <?php else: ?>
    <p class="small">Log a bleed below, or mark day 1 of a cycle, so Pillbox can work out your phases.</p>
    <?php endif; ?>
    <p class="small">If things get very hard, your safety plan and crisis lines (Samaritans on 116 123) are in <a href="<?= h(url('help.php')) ?>">Help</a>.</p>
    <?php if ($canEdit): ?>
    <div class="inline-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pmdd_headsup"><input type="hidden" name="on" value="<?= $user['pmdd_headsup'] ? 0 : 1 ?>">
            <button class="btn btn-small" type="submit"><?= $user['pmdd_headsup'] ? 'Turn off the heads-up' : 'Heads-up before harder days' ?></button></form>
        <details class="q-edit"><summary class="btn btn-small">No bleed? Mark day 1</summary>
            <p class="muted small">On testosterone, after a hysterectomy with ovaries kept, or with irregular cycles, mark the day your symptoms suggest a new cycle starting.</p>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="pmdd_marker">
                <input type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>" aria-label="Day 1"><button class="btn btn-small btn-primary" type="submit">Mark</button></form>
            <?php foreach ($markers as $m): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="pmdd_marker_delete"><input type="hidden" name="date" value="<?= h($m) ?>">
                <span class="small"><?= h(date('j M Y', strtotime($m))) ?></span> <button class="btn-link danger small" type="submit">Remove</button></form>
            <?php endforeach; ?>
        </details>
    </div>
    <?php endif; ?>
</section>

<section class="card" id="pmdd-log">
    <h2>Today</h2>
    <p class="muted small">Rate each day, ideally every day of the month for at least 2 cycles: a few seconds is enough. 1 is not at all, 6 is extreme.</p>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $todayLog ? '' : ' open' ?>><summary><?= $todayLog ? 'Update today' : 'Quick check-in' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="pmdd_log">
            <label class="narrow">Date <input type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
            <?php $item = function (int $n, array $i) use ($todayLog) { $v = $todayLog['scores'][$n] ?? 0; ?>
                <div class="drsp-row"><span><?= h($i[0]) ?></span>
                    <span class="drsp-scale" role="radiogroup" aria-label="<?= h($i[0]) ?>"><?php foreach (DRSP_SCALE as $k => $l): ?>
                        <label title="<?= h($l) ?>"><input type="radio" name="s[<?= $n ?>]" value="<?= $k ?>"<?= checked($v === $k) ?>><span><?= $k ?></span></label><?php endforeach; ?></span></div>
            <?php }; ?>
            <?php foreach (DRSP_ITEMS as $n => $i) { if ($i[2]) { $item($n, $i); } } ?>
            <details class="q-edit"<?= $todayLog && !$todayLog['quick'] ? ' open' : '' ?>><summary>All the questions (full DRSP)</summary>
                <?php foreach (DRSP_ITEMS as $n => $i) { if (!$i[2]) { $item($n, $i); } } ?>
            </details>
            <fieldset><legend>Today I&hellip;</legend><div class="checks"><?php foreach (PMDD_LIFESTYLE as $k => $l): ?>
                <label class="check"><input type="checkbox" name="lifestyle[]" value="<?= $k ?>"<?= checked(in_array($k, $todayLog['lifestyle'] ?? [], true)) ?>> <?= h($l) ?></label>
            <?php endforeach; ?></div></fieldset>
            <label>Notes <input name="notes" maxlength="500" value="<?= h((string) ($todayLog['notes'] ?? '')) ?>"></label>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
</section>

<section class="card">
    <h2>By cycle day</h2>
    <?= pmdd_heatmap($uid, $logs, $today) ?>
    <p class="muted small chart-key"><span class="key luteal-key"></span> the 2 weeks before a bleed (luteal phase) ·
        <span class="key heat-1"></span><span class="key heat-3"></span><span class="key heat-6"></span> not at all to extreme · blank: not logged</p>
    <?php if ($comparisons): ?>
    <h3>Before and after each bleed</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Cycle</th><th class="num">Week before</th><th class="num">Days 4 to 10</th><th class="num">Change</th><th>Pattern</th></tr></thead>
        <tbody><?php foreach ($comparisons as $c): ?>
            <tr><td><?= h(date('j M', strtotime($c['start']))) ?> to <?= h(date('j M', strtotime($c['next']))) ?></td>
                <td class="num"><?= $c['pre'] !== null ? h(number_format($c['pre'], 1)) : '–' ?></td><td class="num"><?= $c['post'] !== null ? h(number_format($c['post'], 1)) : '–' ?></td>
                <td class="num"><?= $c['change'] !== null ? h(sprintf('%+d%%', (int) round($c['change']))) : '–' ?></td><td class="small"><?= h(PMDD_PATTERNS[$c['pattern']]) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="muted small">A rise of 30% or more in the week before a bleed, settling a few days after it starts, is the usual premenstrual pattern. If symptoms are also there after the bleed and just get worse
        before it, that's premenstrual worsening of something ongoing (such as depression, ADHD or migraine). Show your GP: this isn't a diagnosis.</p>
    <?php endif; ?>
    <?php foreach ($lifestyle as [$label, $with, $without, $n]): ?>
    <p class="small">Days marked "<?= h(mb_strtolower($label)) ?>": average <?= h(number_format($with, 1)) ?>, other days <?= h(number_format($without, 1)) ?> (<?= $n ?> days). A pattern, not proof of a cause.</p>
    <?php endforeach; ?>
</section>

<section class="card">
    <h2>Treatments</h2>
    <?php foreach ($treatments as $t): $m = $t['med']; ?>
    <p class="small"><strong><?= h($m['name']) ?></strong> <span class="muted">since <?= h(date('j M Y', strtotime($m['start_date']))) ?></span>:
        <?= $t['before'] !== null && $t['after'] !== null ? 'week-before-a-bleed average ' . h(number_format($t['before'], 1)) . ' before starting, ' . h(number_format($t['after'], 1)) . ' since'
            : 'needs a few cycles logged before and after starting to compare' ?>.</p>
    <?php endforeach; ?>
    <?php if (share_can('doses', 'edit')): ?>
    <p class="small">Add a treatment (opens the medication form filled in; an SSRI can be set to only the 2 weeks before a period, from your cycle):</p>
    <div class="preset-list"><?php foreach (PMDD_PRESETS as $key => $p): ?><a class="btn btn-small" href="<?= h(url('medication_edit.php?preset=' . $key)) ?>">+ <?= h($p['label']) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
    <p class="small">CBT or other talking therapy: add sessions as <a href="<?= h(url('appointment_edit.php?kind=mental_health')) ?>">appointments</a>, with notes.</p>
    <p class="small">More information and support: <a href="https://iapmd.org" target="_blank" rel="noopener">IAPMD</a> (free DRSP charts and peer support) ·
        <a href="https://www.pms.org.uk" target="_blank" rel="noopener">NAPS</a> · <a href="https://www.nhs.uk/conditions/pre-menstrual-syndrome/" target="_blank" rel="noopener">NHS: PMS</a></p>
    <?php if ($canEdit): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pmdd_mode"><input type="hidden" name="on" value="0">
        <button class="btn-link small" type="submit">Stop premenstrual tracking</button> <span class="muted small">(what you've logged is kept)</span></form>
    <?php endif; ?>
</section>
    <?php
}
