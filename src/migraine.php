<?php
declare(strict_types=1);

/*
 * Migraine and headaches (a Care category, care.php?area=migraine).
 * - Headache diary: start/end, severity, location, aura, symptoms, triggers, and medicines taken
 *   with how well they helped (optionally logged as a dose, so stock and Today stay in step).
 * - Monthly headache, migraine and acute-medicine days (including as-needed doses logged on
 *   Today), with medication overuse warnings (NHS): 15+ days a month of simple painkillers, or
 *   10+ of triptans, opioids or combination painkillers; an early nudge from 8 days.
 * - Preventives with a 2 to 3 month effectiveness review; CGRP evidence (migraine days a month
 *   and preventives tried, as NICE asks); topiramate pregnancy prevention; combined hormonal
 *   contraception with migraine with aura; red flags; menstrual migraine (days -2 to +3).
 * - A clinician summary (migraine_summary.php).
 */

const TRIPTAN_PATTERN = '/\b(\w*triptan|imigran|zomig|maxalt|relpax|naramig|almogran|migard)\b/i';
const OPIOID_PATTERN = '/\b(codeine|co-?codamol|dihydrocodeine|co-?dydramol|tramadol|morphine|oxycodone|tapentadol|solpadeine|migraleve|syndol|nurofen plus)\b/i';
const COMBINATION_PATTERN = '/\b(co-?codamol|co-?dydramol|solpadeine|migraleve|syndol|anadin extra|excedrin|paramax|migramax|nurofen plus)\b/i';
const SIMPLE_PAIN_PATTERN = '/\b(paracetamol|ibuprofen|aspirin|naproxen|diclofenac|nurofen|anadin|calpol|panadol|feverfew|mefenamic)\b/i';
const PREVENTIVE_PATTERN = '/\b(propranolol|amitriptyline|nortriptyline|candesartan|topiramate|topamax|pizotifen|flunarizine|sodium valproate|botulinum|botox|erenumab|aimovig|fremanezumab|ajovy|galcanezumab|emgality|eptinezumab|vyepti|rimegepant|vydura|atogepant|aquipta)\b/i';
const CGRP_PATTERN = '/\b(erenumab|aimovig|fremanezumab|ajovy|galcanezumab|emgality|eptinezumab|vyepti|rimegepant|vydura|atogepant|aquipta)\b/i';
const COMBINED_HC_PATTERN = '/\b(combined (contraceptive )?pill|ethinylestradiol|microgynon|rigevidon|levest|ovranette|maexeni|yasmin|lucette|cilest|marvelon|gedarel|femodene|katya|millinette|eloine|zoely|qlaira|evra|nuvaring|svelte|combined contraceptive)\b/i';

const HEADACHE_LOCATIONS = ['one_side' => 'One side', 'both' => 'Both sides', 'front' => 'Forehead or behind the eyes', 'back' => 'Back of the head or neck', 'all' => 'All over'];
const HEADACHE_SYMPTOMS = ['nausea' => 'Nausea', 'vomiting' => 'Vomiting', 'light' => 'Light sensitivity', 'sound' => 'Sound sensitivity', 'smell' => 'Smell sensitivity',
    'throbbing' => 'Throbbing', 'worse_moving' => 'Worse with moving', 'neck' => 'Neck pain', 'dizzy' => 'Dizziness'];
const HEADACHE_TRIGGERS = ['sleep' => 'Too little or too much sleep', 'missed_meal' => 'Missed meal', 'dehydration' => 'Not enough to drink', 'alcohol' => 'Alcohol',
    'caffeine' => 'Caffeine (more or less than usual)', 'stress' => 'Stress, or relaxing after it', 'screen' => 'Screen time', 'weather' => 'Weather change',
    'period' => 'Period or cycle', 'exercise' => 'Exercise', 'bright_light' => 'Bright or flickering light', 'smells' => 'Strong smells'];
const HEADACHE_SEVERITY = [1 => 'Mild', 2 => 'Moderate', 3 => 'Severe'];
const RELIEF = [0 => "Didn't help", 1 => 'Helped a little', 2 => 'Helped a lot', 3 => 'Took it away'];

/** What kind of acute medicine (for overuse limits): triptan | opioid | combination | simple | null. */
function acute_class(string $name): ?string
{
    return match (true) {
        (bool) preg_match(TRIPTAN_PATTERN, $name) => 'triptan',
        (bool) preg_match(COMBINATION_PATTERN, $name) => 'combination',
        (bool) preg_match(OPIOID_PATTERN, $name) => 'opioid',
        (bool) preg_match(SIMPLE_PAIN_PATTERN, $name) => 'simple',
        default => null,
    };
}

function headache_episodes(int $userId, string $fromUtc): array
{
    $rows = q('SELECT * FROM headache_episodes WHERE user_id = ? AND started_at >= ? ORDER BY started_at DESC', [$userId, $fromUtc])->fetchAll();
    foreach ($rows as &$r) {
        $r['symptoms'] = array_filter(explode(',', (string) $r['symptoms']));
        $r['triggers'] = array_filter(explode(',', (string) $r['triggers']));
        $r['meds'] = q('SELECT * FROM episode_meds WHERE episode_id = ? ORDER BY taken_at', [$r['id']])->fetchAll();
    }
    return $rows;
}

/**
 * Days with a headache, a migraine, and acute medicine (by class) between two local dates.
 * Acute days include as-needed doses logged on Today and medicines logged with a headache.
 */
function headache_days(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $tz = $from->getTimezone();
    $fromUtc = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $toUtc = $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $out = ['headache' => [], 'migraine' => [], 'acute' => [], 'by_class' => ['simple' => [], 'triptan' => [], 'opioid' => [], 'combination' => []]];
    foreach (q('SELECT started_at, ended_at, migraine FROM headache_episodes WHERE user_id = ? AND started_at < ? AND COALESCE(ended_at, started_at) >= ?', [$userId, $toUtc, $fromUtc]) as $e) {
        $start = (new DateTimeImmutable($e['started_at'] . ' UTC'))->setTimezone($tz);
        $end = (new DateTimeImmutable(($e['ended_at'] ?? $e['started_at']) . ' UTC'))->setTimezone($tz);
        for ($d = new DateTimeImmutable($start->format('Y-m-d'), $tz); $d <= $end && $d <= $to; $d = $d->modify('+1 day')) {
            if ($d >= $from) {
                $out['headache'][$d->format('Y-m-d')] = true;
                if ($e['migraine']) {
                    $out['migraine'][$d->format('Y-m-d')] = true;
                }
            }
        }
    }
    $add = function (string $name, string $atUtc) use (&$out, $tz) {
        $class = acute_class($name);
        if ($class) {
            $day = (new DateTimeImmutable($atUtc . ' UTC'))->setTimezone($tz)->format('Y-m-d');
            $out['acute'][$day] = true;
            $out['by_class'][$class][$day] = true;
        }
    };
    foreach (q('SELECT m.name, m.taken_at FROM episode_meds m JOIN headache_episodes e ON e.id = m.episode_id WHERE e.user_id = ? AND m.taken_at >= ? AND m.taken_at < ?', [$userId, $fromUtc, $toUtc]) as $m) {
        $add($m['name'], $m['taken_at']);
    }
    foreach (q("SELECT d.taken_at, m.name FROM dose_logs d JOIN medications m ON m.id = d.medication_id WHERE d.user_id = ? AND d.status = 'taken' AND m.as_needed = 1 AND d.taken_at >= ? AND d.taken_at < ?",
        [$userId, $fromUtc, $toUtc]) as $m) {
        $add($m['name'], $m['taken_at']);
    }
    return array_map(fn ($v) => is_array($v) && !isset($v['simple']) ? count($v) : array_map('count', $v), $out);
}

/** Medication overuse warnings (NHS thresholds) for days in the last 30. */
function overuse_warnings(array $days): array
{
    $out = [];
    $strong = $days['by_class']['triptan'] + $days['by_class']['opioid'] + $days['by_class']['combination'];
    if ($days['by_class']['simple'] >= 15) {
        $out[] = ['bad', "Simple painkillers on {$days['by_class']['simple']} days in the last 30: 15 or more days a month can cause medication overuse headache."];
    }
    foreach (['triptan' => 'Triptans', 'opioid' => 'Codeine or other opioids', 'combination' => 'Combination painkillers'] as $k => $l) {
        if ($days['by_class'][$k] >= 10) {
            $out[] = ['bad', "$l on {$days['by_class'][$k]} days in the last 30: 10 or more days a month can cause medication overuse headache."];
        }
    }
    // A mix of different acute medicines on 10 or more days counts too, if any are triptans, opioids or combinations.
    if (!$out && $days['acute'] >= 10 && $strong > 0) {
        $out[] = ['bad', "Different headache medicines on {$days['acute']} days in the last 30: a mix on 10 or more days a month can cause medication overuse headache."];
    }
    if (!$out && $days['acute'] >= 8) {
        $out[] = ['warn', "Painkillers or triptans on {$days['acute']} days in the last 30. Using them on more days than this can lead to rebound headaches: worth a GP review, especially about preventive treatment."];
    } elseif ($out) {
        $out[] = ['bad', 'If this goes on for more than 3 months it can cause rebound headaches. See your GP: there are ways to break the cycle and preventive treatments.'];
    }
    return $out;
}

/** Medicine safety warnings that involve migraine (topiramate in pregnancy; combined contraception with aura). */
function migraine_safety_warnings(array $user, array $meds): array
{
    $out = [];
    $uid = (int) $user['id'];
    if (array_filter($meds, fn ($m) => preg_match('/\btopiramate|topamax\b/i', $m['name'])) && body_has($user, 'uterus') !== false) {
        $out[] = 'Topiramate can harm an unborn baby. If you could become pregnant, you need highly effective contraception and a yearly review under the topiramate pregnancy prevention programme. Talk to your prescriber if you are planning a pregnancy, and don\'t stop it suddenly.';
    }
    $chc = array_filter($meds, fn ($m) => preg_match(COMBINED_HC_PATTERN, $m['name'] . ' ' . $m['reason']));
    $aura = (bool) q('SELECT 1 FROM headache_episodes WHERE user_id = ? AND aura = 1 AND started_at >= ? LIMIT 1', [$uid, gmdate('Y-m-d H:i:s', time() - 400 * 86400)])->fetch();
    if ($chc && $aura) {
        $out[] = 'You take ' . implode(', ', array_map(fn ($m) => $m['name'], $chc)) . ' and have had migraine with aura. Combined hormonal contraception is not recommended with migraine with aura because it raises the risk of stroke: talk to your GP or a sexual health clinic soon about other contraception. Don\'t just stop it without other protection.';
    }
    return $out;
}

/** Share of migraine days that fall in days -2 to +3 of a period (menstrual migraine pattern). */
function menstrual_migraine(int $userId, array $migraineDays): ?array
{
    $anchors = cycle_anchors($userId);
    if (count($anchors) < 2 || count($migraineDays) < 4) {
        return null;
    }
    $window = [];
    foreach ($anchors as $a) {
        for ($i = -2; $i <= 3; $i++) {
            $window[(new DateTimeImmutable($a))->modify("$i days")->format('Y-m-d')] = true;
        }
    }
    $in = count(array_intersect_key($migraineDays, $window));
    return ['in' => $in, 'total' => count($migraineDays), 'share' => $in / count($migraineDays)];
}

/** Actions posted from care.php?area=migraine ('mg_...'); always redirects. */
function migraine_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=migraine';
    if (str_starts_with(post('action'), 'mg_weather_')) {
        weather_handle_post($user);
    }
    $when = function (string $d, string $t) use ($today) {
        $day = parse_date(post($d)) ?: $today;
        return preg_match('/^\d\d:\d\d$/', post($t)) ? local_to_utc($day->format('Y-m-d') . ' ' . post($t)) : null;
    };
    switch (post('action')) {
        case 'mg_episode':
            $start = $when('start_date', 'start_time') ?? utc_now();
            $end = post('ongoing') === '1' ? null : $when('end_date', 'end_time');
            if ($end !== null && $end < $start) {
                $end = null;
            }
            q('INSERT INTO headache_episodes (user_id, started_at, ended_at, severity, location, migraine, aura, symptoms, triggers, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $uid, min($start, utc_now()), $end, isset(HEADACHE_SEVERITY[(int) post('severity')]) ? (int) post('severity') : null,
                isset(HEADACHE_LOCATIONS[post('location')]) ? post('location') : null, post('migraine') === '1' ? 1 : 0, post('aura') === '1' ? 1 : 0,
                implode(',', array_intersect(array_keys(HEADACHE_SYMPTOMS), (array) ($_POST['symptoms'] ?? []))) ?: null,
                implode(',', array_intersect(array_keys(HEADACHE_TRIGGERS), (array) ($_POST['triggers'] ?? []))) ?: null, trim(mb_substr(post('notes'), 0, 1000)) ?: null,
            ]);
            $id = (int) db()->lastInsertId();
            if (trim(post('med_name')) !== '' || post('medication_id') !== '') {
                migraine_add_med($uid, $id, $start);
            }
            flash('success', 'Headache logged.' . ($end === null ? ' Press "It\'s gone" when it ends.' : ''));
            redirect($back . '#mg-diary');
        case 'mg_end':
            q('UPDATE headache_episodes SET ended_at = ? WHERE id = ? AND user_id = ? AND ended_at IS NULL', [utc_now(), (int) post('episode_id'), $uid]);
            redirect($back . '#mg-diary');
        case 'mg_med':
            $ep = q('SELECT * FROM headache_episodes WHERE id = ? AND user_id = ?', [(int) post('episode_id'), $uid])->fetch();
            if ($ep) {
                migraine_add_med($uid, (int) $ep['id'], utc_now());
            }
            redirect($back . '#mg-diary');
        case 'mg_relief':
            q('UPDATE episode_meds m JOIN headache_episodes e ON e.id = m.episode_id SET m.helped = ? WHERE m.id = ? AND e.user_id = ?',
                [isset(RELIEF[(int) post('helped')]) ? (int) post('helped') : null, (int) post('med_id'), $uid]);
            redirect($back . '#mg-diary');
        case 'mg_delete':
            q('DELETE FROM headache_episodes WHERE id = ? AND user_id = ?', [(int) post('episode_id'), $uid]);
            redirect($back . '#mg-diary');
    }
    redirect($back);
}

/** A medicine taken for a headache: by name or from Meds (then also logged as a dose, if it's as-needed). */
function migraine_add_med(int $uid, int $episodeId, string $defaultAt): void
{
    $med = post('medication_id') !== '' ? find_medication((int) post('medication_id'), $uid) : null;
    $name = $med ? trim($med['name'] . ' ' . $med['strength']) : trim(mb_substr(post('med_name'), 0, 150));
    if ($name === '') {
        return;
    }
    $at = preg_match('/^\d\d:\d\d$/', post('med_time')) ? (local_to_utc(local_time($defaultAt, 'Y-m-d') . ' ' . post('med_time')) ?? $defaultAt) : $defaultAt;
    q('INSERT INTO episode_meds (episode_id, medication_id, name, taken_at, helped) VALUES (?, ?, ?, ?, ?)',
        [$episodeId, $med['id'] ?? null, $name, min($at, utc_now()), isset(RELIEF[(int) post('helped')]) && post('helped') !== '' ? (int) post('helped') : null]);
    if ($med && $med['as_needed'] && post('log_dose') === '1') {
        $amount = (float) ($med['prn_dose_amount'] ?? 1);
        q("INSERT INTO dose_logs (user_id, medication_id, status, amount, taken_at, notes) VALUES (?, ?, 'taken', ?, ?, 'Headache')", [$uid, $med['id'], $amount, min($at, utc_now())]);
        adjust_stock((int) $med['id'], -$amount);
    }
}

/** The migraine sections at the top of care.php?area=migraine. */
function migraine_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $todayYmd = $today->format('Y-m-d');
    $tz = $today->getTimezone();
    $episodes = headache_episodes($uid, $today->modify('-120 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    $ongoing = array_values(array_filter($episodes, fn ($e) => $e['ended_at'] === null && $e['started_at'] > gmdate('Y-m-d H:i:s', time() - 3 * 86400)));
    $last30 = headache_days($uid, $today->modify('-29 days'), $today);
    $warnings = overuse_warnings($last30);
    $meds = user_medications($uid);
    $acuteMeds = array_filter($meds, fn ($m) => acute_class($m['name']));
    $preventives = array_filter(user_medications($uid, false), fn ($m) => preg_match(PREVENTIVE_PATTERN, $m['name']));
    $safety = migraine_safety_warnings($user, $meds);
    // Monthly table: the last 3 calendar months and this one.
    $months = [];
    for ($i = 3; $i >= 0; $i--) {
        $first = $today->modify('first day of this month')->modify("-$i months");
        $last = min($first->modify('last day of this month'), $today);
        $months[$first->format('M Y')] = headache_days($uid, $first, $last);
    }
    $migraineDays = [];
    foreach (q('SELECT started_at FROM headache_episodes WHERE user_id = ? AND migraine = 1 AND started_at >= ?', [$uid, gmdate('Y-m-d H:i:s', time() - 365 * 86400)]) as $e) {
        $migraineDays[local_time($e['started_at'], 'Y-m-d')] = true;
    }
    $mm = menstrual_migraine($uid, $migraineDays);
    $triggerCounts = [];
    foreach ($episodes as $e) {
        foreach ($e['triggers'] as $t) {
            $triggerCounts[$t] = ($triggerCounts[$t] ?? 0) + 1;
        }
    }
    arsort($triggerCounts);
    $weather = weather_enabled() ? weather_between($uid, $today->modify('-120 days')->format('Y-m-d'), $todayYmd) : [];
    ?>
<section class="card preg-call">
    <h2>Get help straight away</h2>
    <p class="small">Call 999 or go to A&amp;E for a sudden, severe headache that peaks within a minute or so ("thunderclap"); a headache with a fever and stiff neck or a rash;
        a headache after a head injury; or new weakness, numbness, slurred speech, confusion, a fit, or loss of vision.</p>
</section>
<?php foreach ($safety as $s): ?><p class="flash flash-error"><?= h($s) ?></p><?php endforeach; ?>
<?php foreach ($warnings as [$level, $text]): ?><p class="flash flash-<?= $level === 'bad' ? 'error' : 'warning' ?>"><?= h($text) ?></p><?php endforeach; ?>

<section class="card" id="mg-diary">
    <div class="card-head">
        <h2>Headache diary <?= help_link('migraine', 'headache-diary', 'the headache diary') ?></h2>
        <a class="btn btn-small" href="<?= h(url('migraine_summary.php')) ?>">Clinician summary</a>
    </div>
    <div class="tiles">
        <div class="tile"><span class="tile-label">Headache days</span><span class="tile-value"><?= $last30['headache'] ?></span><span class="tile-meta">last 30 days</span></div>
        <div class="tile"><span class="tile-label">Migraine days</span><span class="tile-value"><?= $last30['migraine'] ?></span><span class="tile-meta">last 30 days</span></div>
        <div class="tile"><span class="tile-label">Painkiller or triptan days</span><span class="tile-value<?= $last30['acute'] >= 8 ? ' warn' : '' ?>"><?= $last30['acute'] ?></span><span class="tile-meta">last 30 days</span></div>
    </div>
    <?php foreach ($ongoing as $e): ?>
    <div class="flash flash-info notice-row"><span>Headache since <?= h(local_time($e['started_at'], 'D H:i')) ?></span>
        <?php if ($canEdit): ?><span class="inline-actions">
            <details class="q-edit"><summary class="btn btn-small">Took something</summary>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="mg_med"><input type="hidden" name="episode_id" value="<?= (int) $e['id'] ?>">
                    <?php migraine_med_fields($acuteMeds); ?><button class="btn btn-small btn-primary" type="submit">Save</button></form>
            </details>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mg_end"><input type="hidden" name="episode_id" value="<?= (int) $e['id'] ?>"><button class="btn btn-small btn-primary" type="submit">It's gone</button></form>
        </span><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $episodes ? '' : ' open' ?>><summary>Log a headache</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="mg_episode">
            <div class="grid-2">
                <label>Started <span class="range-pair"><input type="date" name="start_date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"><input type="time" name="start_time" value="<?= h(local_time(utc_now(), 'H:i')) ?>"></span></label>
                <label>Ended <span class="range-pair"><input type="date" name="end_date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"><input type="time" name="end_time"></span></label>
                <label>How bad <select name="severity"><?php foreach (HEADACHE_SEVERITY as $k => $l): ?><option value="<?= $k ?>"<?= selected($k === 2) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Where <select name="location"><option value="">&ndash;</option><?php foreach (HEADACHE_LOCATIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="checks">
                <label class="check"><input type="checkbox" name="ongoing" value="1"> Still going</label>
                <label class="check"><input type="checkbox" name="migraine" value="1"> It's a migraine</label>
                <label class="check"><input type="checkbox" name="aura" value="1"> With aura <span class="muted small">(e.g. zigzags, blind spots, tingling, before or with it)</span></label>
            </div>
            <fieldset><legend>Symptoms</legend><div class="checks"><?php foreach (HEADACHE_SYMPTOMS as $k => $l): ?><label class="check"><input type="checkbox" name="symptoms[]" value="<?= $k ?>"> <?= h($l) ?></label><?php endforeach; ?></div></fieldset>
            <fieldset><legend>Possible triggers</legend><div class="checks"><?php foreach (HEADACHE_TRIGGERS as $k => $l): ?><label class="check"><input type="checkbox" name="triggers[]" value="<?= $k ?>"> <?= h($l) ?></label><?php endforeach; ?></div></fieldset>
            <fieldset><legend>Took something?</legend><div class="inline-form"><?php migraine_med_fields($acuteMeds); ?></div></fieldset>
            <label>Notes <input name="notes" maxlength="1000"></label>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($episodes): ?>
    <ul class="plain"><?php foreach (array_slice($episodes, 0, 15) as $e): ?>
        <li class="logline"><span><strong><?= h(local_time($e['started_at'], 'D j M H:i')) ?></strong><?= $e['ended_at'] ? ' to ' . h(local_time($e['ended_at'], local_time($e['ended_at'], 'Y-m-d') === local_time($e['started_at'], 'Y-m-d') ? 'H:i' : 'D H:i')) : ' (ongoing)' ?>
            · <?= h(HEADACHE_SEVERITY[$e['severity']] ?? '') ?><?= $e['migraine'] ? ' migraine' : ' headache' ?><?= $e['aura'] ? ' with aura' : '' ?>
            <span class="muted small"><?= $e['triggers'] ? ' · ' . h(implode(', ', array_map(fn ($t) => mb_strtolower(HEADACHE_TRIGGERS[$t] ?? $t), $e['triggers']))) : '' ?><?= ($wn = weather_note($weather[local_time($e['started_at'], 'Y-m-d')] ?? null)) !== '' ? ' · ' . h($wn) : '' ?></span>
            <?php foreach ($e['meds'] as $m): ?><br><span class="small">&#x1F48A; <?= h($m['name']) ?> at <?= h(local_time($m['taken_at'], 'H:i')) ?>:
                <?php if ($canEdit): ?><form method="post" class="inline-relief"><?= csrf_field() ?><input type="hidden" name="action" value="mg_relief"><input type="hidden" name="med_id" value="<?= (int) $m['id'] ?>">
                    <select name="helped" data-autosubmit aria-label="How well it helped"><option value="">How well did it help?</option><?php foreach (RELIEF as $k => $l): ?><option value="<?= $k ?>"<?= selected($m['helped'] !== null && (int) $m['helped'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></form>
                <?php else: ?><?= h(RELIEF[$m['helped']] ?? '') ?><?php endif; ?></span><?php endforeach; ?></span>
            <?php if ($canEdit): ?><form method="post" data-confirm="Delete this headache?"><?= csrf_field() ?><input type="hidden" name="action" value="mg_delete"><input type="hidden" name="episode_id" value="<?= (int) $e['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Month by month</h2>
    <div class="table-wrap"><table><thead><tr><th></th><th class="num">Headache days</th><th class="num">Migraine days</th><th class="num">Painkiller or triptan days</th><th class="num">Triptans</th><th class="num">Opioid or combination</th></tr></thead><tbody>
        <?php foreach ($months as $label => $d): ?><tr><td><?= h($label) ?></td><td class="num"><?= $d['headache'] ?></td><td class="num"><?= $d['migraine'] ?></td><td class="num"><?= $d['acute'] ?></td>
            <td class="num"><?= $d['by_class']['triptan'] ?></td><td class="num"><?= $d['by_class']['opioid'] + $d['by_class']['combination'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($triggerCounts && count($episodes) >= 3): ?>
    <p class="small">Most often noted with your headaches (last 4 months): <?= h(implode(', ', array_map(fn ($k, $n) => mb_strtolower(HEADACHE_TRIGGERS[$k] ?? $k) . " ($n of " . count($episodes) . ')', array_keys(array_slice($triggerCounts, 0, 4, true)), array_slice($triggerCounts, 0, 4, true)))) ?>.</p>
    <?php endif; ?>
    <?php if ($mm): ?>
    <p class="small"><?= $mm['in'] ?> of your <?= $mm['total'] ?> migraine days in the last year were in the 2 days before to 3 days after a period started<?= $mm['share'] >= 0.5 ? ': that suggests menstrual migraine, which can be treated differently, so mention it to your GP.' : '.' ?></p>
    <?php endif; ?>
</section>

<?php weather_section($user, $today, $canEdit); ?>

<section class="card">
    <h2>Preventive treatment</h2>
    <?php if (!$preventives): ?><p class="small">If you have migraines on several days a month, ask your GP about preventive treatment (for example propranolol, amitriptyline, candesartan or topiramate).
        Keep this diary going: it's the evidence they'll look at.</p><?php endif; ?>
    <?php foreach ($preventives as $m):
        $start = new DateTimeImmutable($m['start_date'], $tz);
        $before = headache_days($uid, $start->modify('-90 days'), $start->modify('-1 day'));
        $reviewFrom = $start->modify('+2 months');
        $after = $today >= $reviewFrom ? headache_days($uid, max($start, $today->modify('-89 days')), $today) : null; ?>
    <p class="small"><strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> <span class="muted">from <?= h($start->format('j M Y')) ?><?= !$m['is_active'] ? ' (stopped)' : '' ?></span>:
        <?= $after ? 'migraine days ' . round($before['migraine'] / 3, 1) . ' a month in the 3 months before, ' . round($after['migraine'] / (min(90, $start->diff($today)->days + 1) / 30), 1) . ' a month since. Worth reviewing with your GP.'
            : 'review how well it works after 2 to 3 months (from ' . h($reviewFrom->format('j M')) . ').' ?></p>
    <?php endforeach; ?>
    <?php $tried = count(array_filter($preventives, fn ($m) => !preg_match(CGRP_PATTERN, $m['name'])));
    $avg = round(array_sum(array_map(fn ($d) => $d['migraine'], array_slice($months, 0, 3))) / 3, 1); ?>
    <p class="small"><strong>CGRP medicines:</strong> NICE usually recommends these when someone has at least 4 migraine days a month and at least 3 preventive treatments haven't worked.
        Your diary shows about <?= h(num($avg)) ?> migraine days a month (last 3 full months) and <?= $tried ?> preventive treatment<?= $tried === 1 ? '' : 's' ?> recorded.</p>
    <?php if (array_filter($preventives, fn ($m) => preg_match('/propranolol|candesartan/i', $m['name']))): ?>
    <p class="small">Some preventives are blood pressure medicines: <a href="<?= h(url('readings.php?metric=blood_pressure')) ?>">log your blood pressure</a> now and then.</p>
    <?php endif; ?>
    <?php if (in_array('menopause', user_care_areas($user), true)): ?>
    <p class="small">Migraines can get worse around the menopause. With migraine, HRT patches or gel are usually preferred to tablets: ask your GP.</p>
    <?php endif; ?>
</section>
    <?php
}

/** Fields for a medicine taken for a headache: from Meds or typed, the time, and how well it helped. */
function migraine_med_fields(array $acuteMeds): void
{
    ?>
    <?php if ($acuteMeds): ?><label>Medicine <select name="medication_id"><option value="">&ndash;</option><?php foreach ($acuteMeds as $m): ?><option value="<?= (int) $m['id'] ?>"><?= h(trim($m['name'] . ' ' . $m['strength'])) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label><?= $acuteMeds ? 'or' : 'Medicine' ?> <input name="med_name" maxlength="150" placeholder="e.g. Sumatriptan 50 mg"></label>
    <label>At <input type="time" name="med_time" value="<?= h(local_time(utc_now(), 'H:i')) ?>"></label>
    <label>Helped? <select name="helped"><option value="">Not sure yet</option><?php foreach (RELIEF as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
    <?php if ($acuteMeds): ?><label class="check"><input type="checkbox" name="log_dose" value="1" checked> Log it as a dose</label><?php endif; ?>
    <?php
}
