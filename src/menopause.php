<?php
declare(strict_types=1);

/*
 * Menopause (a Care category): stage, months since the last period (from the period log
 * in Periods and contraception), a daily symptom diary with triggers, the Greene Climacteric Scale,
 * HRT (as medications, with one-tap set-ups), contraception and bleeding notes, work notes,
 * and a one-page GP summary (menopause_summary.php). Checks (HRT review, blood pressure,
 * bone density, Mirena) and routines come from the Care category itself.
 * Following NHS and NICE guidance; it never diagnoses, only points to the GP.
 */

const MENO_STAGES = ['unsure' => 'Not sure yet', 'peri' => 'Perimenopause (periods changing)', 'post' => 'Menopause or after (no periods for 12 months)'];
const MENO_CAUSES = ['natural' => 'Natural', 'surgery' => 'After surgery (e.g. ovaries removed)', 'treatment' => 'After medical treatment (e.g. chemotherapy)', 'unsure' => 'Not sure'];
const MENO_SEVERITY = [0 => 'None', 1 => 'Mild', 2 => 'Moderate', 3 => 'Severe'];

// The daily diary: each rated 0 to 3 (hot flushes are counted separately).
const MENO_SYMPTOMS = [
    'night_sweats' => 'Night sweats',
    'sleep'        => 'Poor sleep',
    'mood'         => 'Low mood',
    'anxiety'      => 'Anxiety',
    'irritability' => 'Irritability',
    'brain_fog'    => 'Brain fog or memory',
    'joints'       => 'Joint or muscle aches',
    'headaches'    => 'Headaches',
    'palpitations' => 'Palpitations',
    'vaginal'      => 'Vaginal dryness',
    'urinary'      => 'Urinary symptoms',
    'libido'       => 'Low libido',
];
const MENO_TRIGGERS = ['alcohol' => 'Alcohol', 'caffeine' => 'Caffeine', 'spicy' => 'Spicy food', 'hot_drinks' => 'Hot drinks', 'stress' => 'Stress'];

// Greene Climacteric Scale: 21 questions, each 0 (not at all) to 3 (extremely).
const GREENE_QUESTIONS = [
    'Heart beating quickly or strongly', 'Feeling tense or nervous', 'Difficulty in sleeping', 'Excitable', 'Attacks of panic',
    'Difficulty in concentrating', 'Feeling tired or lacking in energy', 'Loss of interest in most things', 'Feeling unhappy or depressed',
    'Crying spells', 'Irritability', 'Feeling dizzy or faint', 'Pressure or tightness in head or body', 'Parts of body feel numb or tingling',
    'Headaches', 'Muscle and joint pains', 'Loss of feeling in hands or feet', 'Breathing difficulties', 'Hot flushes', 'Sweating at night',
    'Loss of interest in sex',
];
const GREENE_ANSWERS = [0 => 'Not at all', 1 => 'A little', 2 => 'Quite a bit', 3 => 'Extremely'];
// Subscales by question number (1-based): psychological (anxiety 1-6, depression 7-11), somatic, vasomotor, sexual.
const GREENE_SUBSCALES = ['psych' => [1, 11], 'somatic' => [12, 18], 'vasomotor' => [19, 20], 'sexual' => [21, 21]];

// One-tap HRT set-ups: they open the medication form filled in, to adjust to the prescription.
// 'days' is a weekday mask (Mon 1, Tue 2, Wed 4, Thu 8, Fri 16, Sat 32, Sun 64).
const HRT_PRESETS = [
    'patch'        => ['label' => 'Oestrogen patch', 'name' => 'Oestrogen patch', 'form' => 'patch', 'unit' => 'patch', 'days' => 9, 'time' => '08:00',
        'instructions' => 'Change twice a week (e.g. Monday and Thursday), on clean dry skin below the waist'],
    'gel'          => ['label' => 'Oestrogen gel', 'name' => 'Oestrogen gel', 'form' => 'gel', 'unit' => 'pump', 'days' => EVERY_DAY, 'time' => '08:00', 'amount' => 2,
        'instructions' => 'Number of pumps as prescribed; let it dry before dressing'],
    'spray'        => ['label' => 'Oestrogen spray', 'name' => 'Oestrogen spray', 'form' => 'spray', 'unit' => 'spray', 'days' => EVERY_DAY, 'time' => '08:00'],
    'tablet'       => ['label' => 'Oestrogen tablets', 'name' => 'Oestrogen', 'form' => 'tablet', 'unit' => 'tablet', 'days' => EVERY_DAY, 'time' => '08:00'],
    'combined'     => ['label' => 'Combined HRT tablets', 'name' => 'Combined HRT', 'form' => 'tablet', 'unit' => 'tablet', 'days' => EVERY_DAY, 'time' => '08:00'],
    'progestogen'  => ['label' => 'Progestogen (every day)', 'name' => 'Progesterone', 'form' => 'capsule', 'unit' => 'capsule', 'days' => EVERY_DAY, 'time' => '22:00',
        'instructions' => 'Continuous (every day). Often taken at bedtime'],
    'sequential'   => ['label' => 'Progestogen (part of each cycle)', 'name' => 'Progesterone', 'form' => 'capsule', 'unit' => 'capsule', 'days' => EVERY_DAY, 'time' => '22:00',
        'instructions' => 'Sequential: only on the days of each cycle you were told (e.g. 12 to 14 days a month). Use "Only between certain dates" for each block'],
    'vaginal'      => ['label' => 'Vaginal oestrogen', 'name' => 'Vaginal oestrogen', 'form' => 'cream', 'unit' => 'application', 'days' => 9, 'time' => '22:00',
        'instructions' => 'Often every day for 2 weeks, then twice a week'],
    'testosterone' => ['label' => 'Testosterone', 'name' => 'Testosterone gel', 'form' => 'gel', 'unit' => 'application', 'days' => EVERY_DAY, 'time' => '08:00'],
];

// Recognising menopause treatments among someone's medicines (for the HRT list and the GP summary).
const HRT_PATTERN = '/\b(hrt|menopaus\w*|oestro\w*|oestradiol|estradiol|estrogen\w*|estriol|progest\w*|utrogestan|mirena|levonorgestrel|testosterone|tibolone|evorel|oestrogel|sandrena|lenzetto|femoston|kliofem|elleste|vagifem|ovestin|fezolinetant|clonidine)\b/i';

function menopause_profile(int $userId): array
{
    return q('SELECT * FROM menopause_profiles WHERE user_id = ?', [$userId])->fetch()
        ?: ['user_id' => $userId, 'stage' => null, 'cause' => null, 'hrt_started' => null, 'work_notes' => null];
}

/** The last day of the most recent logged period or bleed. */
function last_period_day(int $userId): ?DateTimeImmutable
{
    $d = q('SELECT MAX(COALESCE(end_date, start_date)) FROM cycle_periods WHERE user_id = ?', [$userId])->fetchColumn();
    return $d ? new DateTimeImmutable($d) : null;
}

/** Whole months between two dates. */
function months_between(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    $diff = $from->diff($to);
    return $diff->invert ? 0 : $diff->y * 12 + $diff->m;
}

function user_age(array $user, ?DateTimeImmutable $on = null): ?int
{
    return $user['date_of_birth'] ? (new DateTimeImmutable($user['date_of_birth']))->diff($on ?? local_today())->y : null;
}

/**
 * Contraception after the last period (NHS): 2 years if under 50 then, 1 year if 50 or over,
 * and generally not needed after 55. @return array{0: ?DateTimeImmutable, 1: string}
 */
function contraception_until(array $user, ?DateTimeImmutable $lastPeriod): array
{
    if (!$lastPeriod) {
        return [null, 'Contraception is still needed until 2 years after your last period if you are under 50, or 1 year if 50 or over. It is generally not needed after 55.'];
    }
    $age = user_age($user, $lastPeriod);
    $until = $lastPeriod->modify($age !== null && $age >= 50 ? '+1 year' : '+2 years');
    if ($user['date_of_birth']) {
        $fiftyFive = (new DateTimeImmutable($user['date_of_birth']))->modify('+55 years');
        $until = min($until, $fiftyFive);
    }
    $why = $age === null ? ' (add your date of birth for a more exact date)' : '';
    return [$until, 'Contraception is still needed until about ' . $until->format('j F Y') . ", based on your last logged period$why. HRT isn't contraception, and bleeds on sequential HRT aren't periods."];
}

/** NHS notes for the age menopause started or symptoms began. */
function menopause_age_note(array $user): ?string
{
    $age = user_age($user);
    return match (true) {
        $age === null => null,
        $age < 40 => 'Menopause before 40 is called premature ovarian insufficiency (POI). It is managed differently (usually with HRT until at least 51), so talk to your GP.',
        $age < 45 => 'Menopause between 40 and 45 is called early menopause. Your GP may suggest a blood test and talk to you about HRT.',
        default => 'Over 45, perimenopause is usually diagnosed from your symptoms alone, without a blood test, so your symptom diary is useful evidence.',
    };
}

/** Medicines that look like menopause treatment. */
function hrt_medications(array $meds): array
{
    return array_values(array_filter($meds, fn ($m) => preg_match(HRT_PATTERN, $m['name'] . ' ' . $m['reason'] . ' ' . $m['instructions'])));
}

/** Diary entries between two dates (inclusive), oldest first, with scores decoded. */
function menopause_logs(int $userId, string $from, string $to): array
{
    $rows = q('SELECT * FROM menopause_logs WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date', [$userId, $from, $to])->fetchAll();
    foreach ($rows as &$r) {
        $r['scores'] = json_decode((string) $r['scores'], true) ?: [];
        $r['triggers'] = array_filter(explode(',', (string) $r['triggers']));
    }
    return $rows;
}

/** Averages over some diary entries: hot flushes a day, and each symptom's average severity. */
function menopause_averages(array $logs): array
{
    $flushDays = array_filter($logs, fn ($l) => $l['flushes'] !== null);
    $out = ['days' => count($logs), 'flushes' => $flushDays ? array_sum(array_column($flushDays, 'flushes')) / count($flushDays) : null, 'symptoms' => []];
    foreach (MENO_SYMPTOMS as $key => $label) {
        $vals = array_filter(array_map(fn ($l) => $l['scores'][$key] ?? null, $logs), fn ($v) => $v !== null);
        if ($vals) {
            $out['symptoms'][$key] = array_sum($vals) / count($vals);
        }
    }
    arsort($out['symptoms']);
    return $out;
}

/** Hot flushes on days with each trigger vs without (at least 3 days each). Patterns, not causes. */
function menopause_trigger_patterns(array $logs): array
{
    $out = [];
    $days = array_filter($logs, fn ($l) => $l['flushes'] !== null);
    foreach (MENO_TRIGGERS as $key => $label) {
        $with = array_filter($days, fn ($l) => in_array($key, $l['triggers'], true));
        $without = array_filter($days, fn ($l) => !in_array($key, $l['triggers'], true));
        if (count($with) >= 3 && count($without) >= 3) {
            $a = array_sum(array_column($with, 'flushes')) / count($with);
            $b = array_sum(array_column($without, 'flushes')) / count($without);
            if (abs($a - $b) >= 1) {
                $out[] = [$label, $a, $b, count($with)];
            }
        }
    }
    return $out;
}

/** Score a completed Greene scale: total and subscales. */
function greene_score(array $answers): array
{
    $out = ['total' => array_sum($answers)];
    foreach (GREENE_SUBSCALES as $key => [$from, $to]) {
        $out[$key] = array_sum(array_slice($answers, $from - 1, $to - $from + 1));
    }
    return $out;
}

/** Actions posted from the Menopause sections of care.php (all 'meno_...'); always redirects. */
function menopause_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=menopause';
    switch (post('action')) {
        case 'meno_profile':
            $hrt = parse_date(post('hrt_started'));
            q('INSERT INTO menopause_profiles (user_id, stage, cause, hrt_started) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE stage = VALUES(stage), cause = VALUES(cause), hrt_started = VALUES(hrt_started)',
                [$uid, isset(MENO_STAGES[post('stage')]) ? post('stage') : null, isset(MENO_CAUSES[post('cause')]) ? post('cause') : null,
                    $hrt && $hrt <= $today ? $hrt->format('Y-m-d') : null]);
            flash('success', 'Saved.');
            redirect($back . '#menopause');
        case 'meno_work':
            q('INSERT INTO menopause_profiles (user_id, work_notes) VALUES (?, ?) ON DUPLICATE KEY UPDATE work_notes = VALUES(work_notes)',
                [$uid, trim(mb_substr(post('work_notes'), 0, 5000)) ?: null]);
            flash('success', 'Work notes saved.');
            redirect($back . '#work');
        case 'meno_bleed':
            $start = parse_date(post('start_date'));
            if (!$start || $start > $today) {
                flash('error', 'Enter the date the bleeding started.');
                redirect($back . '#menopause');
            }
            $before = q('SELECT MAX(COALESCE(end_date, start_date)) FROM cycle_periods WHERE user_id = ? AND start_date < ?', [$uid, $start->format('Y-m-d')])->fetchColumn();
            $profile = menopause_profile($uid);
            q('INSERT INTO cycle_periods (user_id, start_date, flow, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE flow = VALUES(flow)',
                [$uid, $start->format('Y-m-d'), isset(CYCLE_FLOW[post('flow')]) ? post('flow') : null, 'Logged in Menopause']);
            $gap = $before ? (new DateTimeImmutable($before))->diff($start)->days : null;
            if (($gap !== null && $gap >= 365) || $profile['stage'] === 'post') {
                flash('error', 'Bleeding 12 months or more after your last period needs checking. Book a GP appointment: it is usually nothing serious, but it should always be looked at.');
            } elseif ($profile['hrt_started'] && $start > (new DateTimeImmutable($profile['hrt_started']))->modify('+6 months')) {
                flash('error', "Saved. Unexpected bleeding after the first 3 to 6 months of HRT should be checked by your GP (bleeds you expect on sequential HRT are fine).");
            } else {
                flash('success', 'Saved. It is also in your period log in Periods and contraception.');
            }
            redirect($back . '#menopause');
        case 'meno_log':
            $day = parse_date(post('log_date')) ?: $today;
            $scores = [];
            foreach (MENO_SYMPTOMS as $key => $label) {
                if (isset($_POST['s'][$key]) && $_POST['s'][$key] !== '') {
                    $scores[$key] = max(0, min(3, (int) $_POST['s'][$key]));
                }
            }
            $triggers = array_intersect(array_keys(MENO_TRIGGERS), (array) ($_POST['triggers'] ?? []));
            q('INSERT INTO menopause_logs (user_id, log_date, flushes, flush_severity, scores, triggers, notes) VALUES (?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE flushes = VALUES(flushes), flush_severity = VALUES(flush_severity), scores = VALUES(scores), triggers = VALUES(triggers), notes = VALUES(notes)', [
                $uid, min($day, $today)->format('Y-m-d'), post('flushes') !== '' ? max(0, min(99, (int) post('flushes'))) : null,
                post('flush_severity') !== '' ? max(0, min(3, (int) post('flush_severity'))) : null,
                json_encode($scores), implode(',', $triggers), trim(mb_substr(post('notes'), 0, 500)) ?: null,
            ]);
            flash('success', 'Diary saved for ' . min($day, $today)->format('j M') . '.');
            redirect($back . '#diary');
        case 'meno_log_delete':
            q('DELETE FROM menopause_logs WHERE id = ? AND user_id = ?', [(int) post('log_id'), $uid]);
            redirect($back . '#diary');
        case 'meno_greene':
            $answers = [];
            foreach (array_keys(GREENE_QUESTIONS) as $i) {
                if (!isset($_POST['g'][$i]) || $_POST['g'][$i] === '') {
                    flash('error', 'Answer all 21 questions (choose "Not at all" if it does not apply).');
                    redirect($back . '#greene');
                }
                $answers[] = max(0, min(3, (int) $_POST['g'][$i]));
            }
            $s = greene_score($answers);
            q('INSERT INTO menopause_scores (user_id, taken_on, answers, total, psych, somatic, vasomotor, sexual) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, $today->format('Y-m-d'), implode(',', $answers), $s['total'], $s['psych'], $s['somatic'], $s['vasomotor'], $s['sexual']]);
            flash('success', "Score saved: {$s['total']} out of 63.");
            redirect($back . '#greene');
        case 'meno_greene_delete':
            q('DELETE FROM menopause_scores WHERE id = ? AND user_id = ?', [(int) post('score_id'), $uid]);
            redirect($back . '#greene');
    }
    redirect($back);
}

/** The Menopause sections shown at the top of care.php?area=menopause. */
function menopause_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $profile = menopause_profile($uid);
    $last = last_period_day($uid);
    $months = $last ? months_between($last, $today) : null;
    [, $contraText] = contraception_until($user, $last);
    $ageNote = menopause_age_note($user);
    $canEdit = share_can('care', 'edit');
    $todayYmd = $today->format('Y-m-d');
    $logs = menopause_logs($uid, $today->modify('-89 days')->format('Y-m-d'), $todayYmd);
    $recent = menopause_averages(array_filter($logs, fn ($l) => $l['log_date'] > $today->modify('-30 days')->format('Y-m-d')));
    $earlier = menopause_averages(array_filter($logs, fn ($l) => $l['log_date'] <= $today->modify('-30 days')->format('Y-m-d') && $l['log_date'] > $today->modify('-60 days')->format('Y-m-d')));
    $patterns = menopause_trigger_patterns($logs);
    $todayLog = array_values(array_filter($logs, fn ($l) => $l['log_date'] === $todayYmd))[0] ?? null;
    $scores = q('SELECT * FROM menopause_scores WHERE user_id = ? ORDER BY taken_on DESC, id DESC LIMIT 12', [$uid])->fetchAll();
    $hrt = hrt_medications(user_medications($uid));
    $stageLabel = MENO_STAGES[$profile['stage'] ?? ''] ?? null;
    ?>
<section class="card" id="menopause">
    <div class="card-head">
        <h2>Your menopause</h2>
        <a class="btn btn-small" href="<?= h(url('menopause_summary.php')) ?>">GP summary</a>
    </div>
    <p class="muted small">Menopause can affect anyone with ovaries, including trans men and non-binary people. It can happen naturally, or after surgery (such as removal of the ovaries)
        or medical treatment (such as chemotherapy). Everyone's is different: use the parts that help.</p>
    <div class="tiles">
        <div class="tile"><span class="tile-label">Stage</span><span class="tile-value small-value"><?= h($stageLabel ? explode(' (', $stageLabel)[0] : 'Not set') ?></span>
            <span class="tile-meta"><?= h($profile['cause'] && $profile['cause'] !== 'unsure' ? MENO_CAUSES[$profile['cause']] : '') ?></span></div>
        <div class="tile"><span class="tile-label">Since your last period</span>
            <span class="tile-value"><?= $months === null ? '–' : $months . ' month' . ($months === 1 ? '' : 's') ?></span>
            <span class="tile-meta"><?= $last ? h($last->format('j M Y')) : 'None logged' ?></span></div>
        <?php if ($profile['hrt_started']): $hrtMonths = months_between(new DateTimeImmutable($profile['hrt_started']), $today); ?>
        <div class="tile"><span class="tile-label">On HRT</span><span class="tile-value"><?= $hrtMonths ?> month<?= $hrtMonths === 1 ? '' : 's' ?></span>
            <span class="tile-meta">since <?= h(date('j M Y', strtotime($profile['hrt_started']))) ?></span></div>
        <?php endif; ?>
    </div>
    <?php if ($months !== null && $months >= 12 && $profile['stage'] !== 'post'): ?>
    <p class="small">12 months without a period is usually when menopause is confirmed (unless HRT or contraception is affecting your bleeding).</p>
    <?php endif; ?>
    <?php if ($ageNote): ?><p class="small"><?= h($ageNote) ?></p><?php endif; ?>
    <p class="small"><?= h($contraText) ?></p>
    <p class="small warn">See your GP about any bleeding 12 months or more after your last period, or unexpected bleeding after the first 3 to 6 months of HRT.</p>
    <?php if ($canEdit): ?>
    <div class="inline-actions">
        <details class="q-edit"><summary class="btn btn-small">Log a period or bleed</summary>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="meno_bleed">
                <label>Started <input type="date" name="start_date" required max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
                <label>Flow <select name="flow"><option value="">&ndash;</option><?php foreach (CYCLE_FLOW as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-small btn-primary" type="submit">Save</button></form>
        </details>
        <details class="q-edit"><summary class="btn btn-small">Stage and HRT start</summary>
            <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="meno_profile">
                <div class="grid-2">
                    <label>Stage <select name="stage"><option value="">&ndash;</option><?php foreach (MENO_STAGES as $k => $l): ?><option value="<?= $k ?>"<?= selected($profile['stage'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                    <label>Cause <select name="cause"><option value="">&ndash;</option><?php foreach (MENO_CAUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($profile['cause'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                    <label><span>Started (or last changed) HRT <span class="muted small">(optional)</span></span>
                        <input type="date" name="hrt_started" max="<?= h($todayYmd) ?>" value="<?= h((string) $profile['hrt_started']) ?>"></label>
                </div>
                <p class="muted small">Stages are a guide: perimenopause can last years, and some people start HRT before their periods stop.</p>
                <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
            </form>
        </details>
    </div>
    <?php endif; ?>
</section>

<section class="card" id="diary">
    <h2>Symptom diary</h2>
    <?php if ($recent['days']): ?>
    <p class="small">Last 30 days (<?= $recent['days'] ?> day<?= $recent['days'] === 1 ? '' : 's' ?> logged)<?= $recent['flushes'] !== null ? ': <strong>' . h(number_format($recent['flushes'], 1)) . '</strong> hot flushes a day on average' . ($earlier['flushes'] !== null ? ' (' . h(number_format($earlier['flushes'], 1)) . ' the 30 days before)' : '') : '' ?>.
        <?php if ($recent['symptoms']): ?>Most troublesome: <?= h(implode(', ', array_map(fn ($k) => mb_strtolower(MENO_SYMPTOMS[$k]), array_slice(array_keys(array_filter($recent['symptoms'], fn ($v) => $v >= 1)), 0, 3))) ?: 'all mild') ?>.<?php endif; ?></p>
    <?php endif; ?>
    <?php foreach ($patterns as [$label, $with, $without, $n]): ?>
    <p class="small">On days you noted <?= h(mb_strtolower($label)) ?>, you logged <?= h(number_format($with, 1)) ?> hot flushes on average, compared with <?= h(number_format($without, 1)) ?> on other days (<?= $n ?> days). A pattern, not proof of a cause.</p>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $todayLog ? '' : ' open' ?>><summary><?= $todayLog ? 'Update today' : 'How has today been?' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="meno_log">
            <div class="grid-2">
                <label>Date <input type="date" name="log_date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
                <label>Hot flushes (how many) <input type="number" name="flushes" min="0" max="99" class="count-input" value="<?= h((string) ($todayLog['flushes'] ?? '')) ?>"></label>
                <label>How bad were they <select name="flush_severity"><option value="">&ndash;</option><?php foreach (MENO_SEVERITY as $k => $l): ?><option value="<?= $k ?>"<?= selected(isset($todayLog['flush_severity']) && (int) $todayLog['flush_severity'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="meno-grid">
                <?php foreach (MENO_SYMPTOMS as $key => $label): $v = $todayLog['scores'][$key] ?? null; ?>
                <label><?= h($label) ?> <select name="s[<?= $key ?>]"><option value="">&ndash;</option><?php foreach (MENO_SEVERITY as $k => $l): ?><option value="<?= $k ?>"<?= selected($v !== null && (int) $v === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <?php endforeach; ?>
            </div>
            <fieldset><legend>Anything that might be a trigger today?</legend>
                <div class="checks"><?php foreach (MENO_TRIGGERS as $k => $l): ?>
                    <label class="check"><input type="checkbox" name="triggers[]" value="<?= $k ?>"<?= checked(in_array($k, $todayLog['triggers'] ?? [], true)) ?>> <?= h($l) ?></label>
                <?php endforeach; ?></div>
            </fieldset>
            <label>Notes <input name="notes" maxlength="500" value="<?= h((string) ($todayLog['notes'] ?? '')) ?>"></label>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($logs): ?>
    <details class="q-edit"><summary>Recent days (<?= count($logs) ?>)</summary>
        <ul class="plain"><?php foreach (array_reverse(array_slice($logs, -21)) as $l): ?>
            <li class="logline"><span><strong><?= h(date('D j M', strtotime($l['log_date']))) ?></strong>
                <?= $l['flushes'] !== null ? ' · ' . (int) $l['flushes'] . ' flush' . ((int) $l['flushes'] === 1 ? '' : 'es') : '' ?>
                <span class="muted small"><?= h(implode(', ', array_map(fn ($k, $v) => MENO_SYMPTOMS[$k] . ' ' . mb_strtolower(MENO_SEVERITY[$v]), array_keys(array_filter($l['scores'])), array_filter($l['scores'])))) ?>
                    <?= $l['triggers'] ? ' · ' . h(implode(', ', array_map(fn ($t) => mb_strtolower(MENO_TRIGGERS[$t] ?? $t), $l['triggers']))) : '' ?></span></span>
                <?php if ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="meno_log_delete"><input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                    <button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></li>
        <?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
</section>

<section class="card" id="greene">
    <h2>Symptom score</h2>
    <p class="muted small">The Greene Climacteric Scale: 21 questions about how much each symptom bothers you at the moment, scored 0 to 63. Doing it every month or two shows how things change, for example on HRT.</p>
    <?php if ($scores): ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th class="num">Total</th><th class="num">Psychological</th><th class="num">Physical</th><th class="num">Flushes and sweats</th><th class="num">Sexual</th><th><span class="sr">Delete</span></th></tr></thead>
        <tbody><?php foreach ($scores as $s): ?>
            <tr><td><?= h(date('j M Y', strtotime($s['taken_on']))) ?></td><td class="num"><strong><?= (int) $s['total'] ?></strong></td><td class="num"><?= (int) $s['psych'] ?> / 33</td>
                <td class="num"><?= (int) $s['somatic'] ?> / 21</td><td class="num"><?= (int) $s['vasomotor'] ?> / 6</td><td class="num"><?= (int) $s['sexual'] ?> / 3</td>
                <td><?php if ($canEdit): ?><form method="post" data-confirm="Delete this score?"><?= csrf_field() ?><input type="hidden" name="action" value="meno_greene_delete"><input type="hidden" name="score_id" value="<?= (int) $s['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"><summary>Answer the questions</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="meno_greene">
            <?php foreach (GREENE_QUESTIONS as $i => $q): ?>
            <fieldset class="phq-q"><legend><?= $i + 1 ?>. <?= h($q) ?></legend>
                <div class="repeat-choice"><?php foreach (GREENE_ANSWERS as $v => $l): ?>
                    <label class="check"><input type="radio" name="g[<?= $i ?>]" value="<?= $v ?>" required> <?= h($l) ?></label>
                <?php endforeach; ?></div>
            </fieldset>
            <?php endforeach; ?>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save score</button></span>
        </form>
    </details>
    <?php endif; ?>
</section>

<section class="card" id="hrt">
    <h2>HRT and treatments</h2>
    <?php if ($hrt): ?>
    <ul class="plain"><?php foreach ($hrt as $m): ?>
        <li class="logline"><span><?= med_dot($m['colour']) ?> <strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?>
            <span class="muted small"><?= $m['stock_count'] !== null ? 'Stock: ' . h(num($m['stock_count'])) : 'Stock not tracked' ?><?= $m['ordered_on'] ? ' · repeat ordered ' . h(date('j M', strtotime($m['ordered_on']))) : '' ?></span></span>
            <a class="btn btn-small" href="<?= h(url('medications.php#med-' . $m['id'])) ?>">Open</a></li>
    <?php endforeach; ?></ul>
    <p class="muted small">Keep stock up to date: there have been HRT shortages, so order repeats in good time. The HRT prepayment certificate (England) is under
        <a href="<?= h(url('care.php?area=prescriptions')) ?>">Prescriptions and payments</a>.</p>
    <?php endif; ?>
    <?php if (share_can('doses', 'edit')): ?>
    <p class="small">Add <?= $hrt ? 'another' : 'your HRT' ?> (opens the medication form filled in, to match your prescription):</p>
    <div class="preset-list">
        <?php foreach (HRT_PRESETS as $key => $p): ?><a class="btn btn-small" href="<?= h(url('medication_edit.php?preset=' . $key)) ?>">+ <?= h($p['label']) ?></a><?php endforeach; ?>
    </div>
    <p class="muted small">Non-hormonal treatments, if prescribed, can be added as any other medication. A Mirena coil can be tracked as a check below.
        If you also take gender-affirming hormones, your clinic or GP will advise how they fit together.</p>
    <?php if (q('SELECT 1 FROM headache_episodes WHERE user_id = ? AND migraine = 1 LIMIT 1', [$uid])->fetch()): ?>
    <p class="small">You've logged migraines: with migraine, HRT as a patch, gel or spray is usually preferred to tablets. Ask your GP.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Bones and heart</h2>
    <p class="small">The risk of thinning bones and heart disease goes up after menopause. The NHS suggests vitamin D (10 micrograms a day), calcium in your diet,
        and weight-bearing exercise such as walking, dancing or strength training. <a href="<?= h(url('medication_edit.php?supplement=1')) ?>">Add a supplement</a></p>
    <p class="small">Breast screening is every 3 years from 50 to 71, and cervical screening continues until 64: see <a href="<?= h(url('care.php?area=screening')) ?>">Screening</a>.</p>
</section>

<section class="card" id="work">
    <h2>Work</h2>
    <p class="muted small">Notes for workplace adjustments: what helps (a fan, flexible hours, breaks, uniform), and what you've agreed. Not included in the GP summary.</p>
    <?php if ($canEdit): ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="meno_work">
        <label class="sr" for="work-notes">Work notes</label><textarea id="work-notes" name="work_notes" rows="4" maxlength="5000"><?= h((string) $profile['work_notes']) ?></textarea>
        <span class="inline-actions"><button class="btn btn-small" type="submit">Save</button></span>
    </form>
    <?php else: ?><p class="small"><?= nl2br(h((string) $profile['work_notes'])) ?></p><?php endif; ?>
</section>
    <?php
}

/** A one-tap hormone set-up by key (menopause HRT or gender-affirming), with its reason filled in. */
function hormone_preset(string $key): ?array
{
    if (isset(HRT_PRESETS[$key])) {
        return HRT_PRESETS[$key] + ['reason' => 'HRT (menopause)'];
    }
    if (isset(THYROID_PRESETS[$key])) {
        return THYROID_PRESETS[$key] + ['reason' => 'Underactive thyroid', 'private' => 0];
    }
    if (isset(PMDD_PRESETS[$key])) {
        return PMDD_PRESETS[$key] + ['reason' => 'Premenstrual symptoms (PMDD)', 'private' => 0];
    }
    return isset(GENDER_PRESETS[$key]) ? GENDER_PRESETS[$key] + ['reason' => 'Gender-affirming hormones', 'private' => 1] : null;
}
