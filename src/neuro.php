<?php
declare(strict_types=1);

/*
 * ADHD and autism (a Care category, care.php?area=neuro): no diagnosis needed.
 * - Identity: self-identified, waiting or diagnosed, per condition.
 * - Waiting lists for assessments (NHS, Right to Choose in England, private) with time waited.
 * - Assessment prep notes, so examples aren't forgotten on the day.
 * - Communication passport ("how to talk to me"), shown full screen or printed (passport.php),
 *   with a copy for an ICE / medical ID, and the Reasonable Adjustment Flag.
 * - Optional, non-pathologising wellbeing log: energy (spoons), sensory load, meltdowns or
 *   shutdowns with possible triggers, sleep, burnout warning signs, with a cycle link.
 * Medication timing and effects are in med_effects.php; checks come from the Care category.
 */

const ND_CONDITIONS = ['adhd' => 'ADHD', 'autism' => 'Autism', 'dyslexia' => 'Dyslexia', 'dyspraxia' => 'Dyspraxia (DCD)', 'tics' => "Tourette's or tics", 'other' => 'Other'];
const ND_STATUS = ['' => '–', 'self' => 'Self-identified', 'waiting' => 'Waiting for an assessment', 'diagnosed' => 'Diagnosed'];
const ND_ROUTES = ['nhs' => 'NHS (local service)', 'rtc' => 'Right to Choose (England)', 'private' => 'Private'];
const ND_PREP_CATEGORIES = ['childhood' => 'Childhood and school', 'daily' => 'Daily life now', 'work' => 'Work or study', 'relationships' => 'Relationships and social',
    'sensory' => 'Sensory', 'strengths' => 'Strengths and interests', 'other' => 'Other'];
const ND_PASSPORT_FIELDS = [
    'communicate'  => ['How I communicate best', 'e.g. Written is easier than phone calls; please email or text'],
    'processing'   => ['Time to process', 'e.g. Give me a moment to answer; one question at a time; write down what I need to do'],
    'sensory'      => ['Sensory needs', 'e.g. Bright lights and busy waiting rooms are hard; I may wear ear defenders'],
    'touch'        => ['Touch', 'e.g. Please tell me before you touch me, and what you are going to do'],
    'overwhelmed'  => ['If I am overwhelmed or in shutdown', 'e.g. I may not be able to speak; give me space and quiet; I can point or write'],
    'appointments' => ['What helps at appointments', 'e.g. The first or last appointment of the day; somewhere quiet to wait; someone with me'],
    'avoid'        => ['Please don\'t', 'e.g. Don\'t take my lack of eye contact as rudeness; don\'t rush me'],
    'contact'      => ['Someone who can help', 'e.g. My partner Sam, 07700 900123'],
];
const ND_BURNOUT_SIGNS = ['exhausted' => 'Exhausted by small things', 'skills' => 'Losing skills I usually have', 'alone' => 'Needing much more time alone',
    'more_events' => 'More meltdowns or shutdowns', 'masking' => 'Harder to mask or cope with people', 'senses' => 'Senses more sensitive than usual'];

function nd_profile(int $userId): array
{
    $row = q('SELECT * FROM nd_profiles WHERE user_id = ?', [$userId])->fetch() ?: ['identity' => null, 'passport' => null, 'raf_asked' => null];
    $row['identity'] = json_decode((string) $row['identity'], true) ?: [];
    $row['passport'] = json_decode((string) $row['passport'], true) ?: [];
    return $row;
}

function nd_save_profile(int $userId, array $profile): void
{
    q('INSERT INTO nd_profiles (user_id, identity, passport, raf_asked) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE identity = VALUES(identity), passport = VALUES(passport), raf_asked = VALUES(raf_asked)',
        [$userId, json_encode($profile['identity']), json_encode($profile['passport']), $profile['raf_asked']]);
}

/** "1 year 4 months" since a date (to another, or today). */
function time_waited(string $from, ?string $to = null): string
{
    $d = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to ?? 'today'));
    return ($d->y ? $d->y . ' year' . ($d->y === 1 ? '' : 's') . ' ' : '') . $d->m . ' month' . ($d->m === 1 ? '' : 's');
}

/** The passport as plain text (for an ICE / medical ID or to paste elsewhere). */
function passport_text(array $user, array $passport): string
{
    $lines = [$user['display_name'] . ($user['pronouns'] ? ' (' . $user['pronouns'] . ')' : '') . ': how to communicate with me'];
    foreach (ND_PASSPORT_FIELDS as $key => [$label]) {
        if (!empty($passport[$key])) {
            $lines[] = $label . ': ' . preg_replace('/\s+/', ' ', $passport[$key]);
        }
    }
    return implode("\n", $lines);
}

/** Actions posted from care.php?area=neuro ('nd_...'); always redirects. */
function nd_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=neuro';
    $profile = nd_profile($uid);
    $date = fn (string $f) => ($d = parse_date(post($f))) && $d <= $today->modify('+5 years') ? $d->format('Y-m-d') : null;
    switch (post('action')) {
        case 'nd_identity':
            $profile['identity'] = array_filter(array_intersect_key((array) ($_POST['identity'] ?? []), ND_CONDITIONS), fn ($v) => isset(ND_STATUS[$v]) && $v !== '');
            nd_save_profile($uid, $profile);
            redirect($back . '#nd-about');
        case 'nd_wait_add':
            q('INSERT INTO nd_waitlist (user_id, assessment, provider, route, referred_on, assessed_on, notes) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $uid, isset(ND_CONDITIONS[post('assessment')]) ? post('assessment') : 'other', trim(mb_substr(post('provider'), 0, 150)) ?: null,
                isset(ND_ROUTES[post('route')]) ? post('route') : null, $date('referred_on'), $date('assessed_on'), trim(mb_substr(post('notes'), 0, 1000)) ?: null]);
            redirect($back . '#nd-wait');
        case 'nd_wait_update':
            q('UPDATE nd_waitlist SET assessed_on = ?, notes = ? WHERE id = ? AND user_id = ?', [$date('assessed_on'), trim(mb_substr(post('notes'), 0, 1000)) ?: null, (int) post('wait_id'), $uid]);
            redirect($back . '#nd-wait');
        case 'nd_wait_delete':
            q('DELETE FROM nd_waitlist WHERE id = ? AND user_id = ?', [(int) post('wait_id'), $uid]);
            redirect($back . '#nd-wait');
        case 'nd_prep_add':
            if (trim(post('note')) !== '') {
                q('INSERT INTO nd_prep (user_id, category, note) VALUES (?, ?, ?)', [$uid, isset(ND_PREP_CATEGORIES[post('category')]) ? post('category') : 'other', trim(mb_substr(post('note'), 0, 2000))]);
            }
            redirect($back . '#nd-prep');
        case 'nd_prep_delete':
            q('DELETE FROM nd_prep WHERE id = ? AND user_id = ?', [(int) post('prep_id'), $uid]);
            redirect($back . '#nd-prep');
        case 'nd_passport':
            foreach (ND_PASSPORT_FIELDS as $key => $_) {
                $profile['passport'][$key] = trim(mb_substr((string) ($_POST['passport'][$key] ?? ''), 0, 600));
            }
            $profile['raf_asked'] = $date('raf_asked');
            nd_save_profile($uid, $profile);
            flash('success', 'Communication passport saved.');
            redirect($back . '#nd-passport');
        case 'nd_log':
            $day = min(parse_date(post('log_date')) ?: $today, $today);
            q('INSERT INTO nd_logs (user_id, log_date, spoons, sensory, event, triggers, sleep_hours, burnout, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE spoons = VALUES(spoons), sensory = VALUES(sensory), event = VALUES(event), triggers = VALUES(triggers),
                   sleep_hours = VALUES(sleep_hours), burnout = VALUES(burnout), notes = VALUES(notes)', [
                $uid, $day->format('Y-m-d'), post('spoons') !== '' ? max(0, min(10, (int) post('spoons'))) : null, post('sensory') !== '' ? max(0, min(5, (int) post('sensory'))) : null,
                in_array(post('event'), ['meltdown', 'shutdown'], true) ? post('event') : null, trim(mb_substr(post('triggers'), 0, 300)) ?: null,
                is_numeric(post('sleep_hours')) ? max(0, min(24, round((float) post('sleep_hours'), 1))) : null,
                implode(',', array_intersect(array_keys(ND_BURNOUT_SIGNS), (array) ($_POST['burnout'] ?? []))) ?: null, trim(mb_substr(post('notes'), 0, 1000)) ?: null,
            ]);
            flash('success', 'Saved for ' . $day->format('j M') . '.');
            redirect($back . '#nd-wellbeing');
    }
    redirect($back);
}

/** The ADHD and autism sections at the top of care.php?area=neuro. */
function nd_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $p = nd_profile($uid);
    $waits = q('SELECT * FROM nd_waitlist WHERE user_id = ? ORDER BY referred_on', [$uid])->fetchAll();
    $prep = q('SELECT * FROM nd_prep WHERE user_id = ? ORDER BY category, created_at', [$uid])->fetchAll();
    $todayYmd = $today->format('Y-m-d');
    $logs = q('SELECT * FROM nd_logs WHERE user_id = ? AND log_date >= ? ORDER BY log_date', [$uid, $today->modify('-59 days')->format('Y-m-d')])->fetchAll();
    $todayLog = array_values(array_filter($logs, fn ($l) => $l['log_date'] === $todayYmd))[0] ?? null;
    $week = array_filter($logs, fn ($l) => $l['log_date'] > $today->modify('-7 days')->format('Y-m-d'));
    $weekSpoons = array_filter(array_column($week, 'spoons'), fn ($v) => $v !== null);
    $weekSigns = $week ? array_unique(array_merge(...array_map(fn ($l) => array_filter(explode(',', (string) $l['burnout'])), array_values($week)))) : [];
    $controlled = array_filter(user_medications($uid), fn ($m) => $m['controlled']);
    ?>
<section class="card" id="nd-about">
    <h2>About you</h2>
    <p class="small">No diagnosis needed: many people are on long waiting lists, or choose not to seek one. Use whatever fits.</p>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="nd_identity">
        <div class="meno-grid"><?php foreach (ND_CONDITIONS as $k => $l): ?>
            <label><?= h($l) ?> <select name="identity[<?= $k ?>]"<?= $canEdit ? '' : ' disabled' ?>><?php foreach (ND_STATUS as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected(($p['identity'][$k] ?? '') === $sk) ?>><?= h($sl) ?></option><?php endforeach; ?></select></label>
        <?php endforeach; ?></div>
        <?php if ($canEdit): ?><span class="inline-actions"><button class="btn btn-small" type="submit">Save</button></span><?php endif; ?>
    </form>
    <?php if ($controlled): $lastBp = q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'blood_pressure' ORDER BY recorded_at DESC LIMIT 1", [$uid])->fetch(); ?>
    <p class="small">Blood pressure and pulse (checked regularly on stimulants): <?= $lastBp ? 'latest ' . (int) $lastBp['value1'] . '/' . (int) $lastBp['value2'] . ($lastBp['value3'] ? ', pulse ' . (int) $lastBp['value3'] : '') . ' on ' . h(local_time($lastBp['recorded_at'], 'j M Y')) : 'none recorded yet' ?>
        · <a href="<?= h(url('readings.php?metric=blood_pressure')) ?>">log one</a></p>
    <p class="small">Your medicine timing and effects: <?php foreach ($controlled as $i => $m): ?><?= $i ? ', ' : '' ?><a href="<?= h(url('med_effects.php?id=' . $m['id'])) ?>"><?= h($m['name']) ?></a><?php endforeach; ?>.</p>
    <?php endif; ?>
</section>

<section class="card" id="nd-passport">
    <div class="card-head">
        <h2>Communication passport <?= help_link('adhd-autism', 'communication-passport', 'the communication passport') ?></h2>
        <?php if (array_filter($p['passport'])): ?><a class="btn btn-small btn-primary" href="<?= h(url('passport.php')) ?>">Show it</a><?php endif; ?>
    </div>
    <p class="small">How to talk to you: show it to a clinician or receptionist, print it, or add it to your ICE / medical ID, so it's there if you can't speak.</p>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= array_filter($p['passport']) ? '' : ' open' ?>><summary><?= array_filter($p['passport']) ? 'Edit' : 'Fill it in' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="nd_passport">
            <?php foreach (ND_PASSPORT_FIELDS as $key => [$label, $hint]): ?>
            <label><?= h($label) ?> <textarea name="passport[<?= $key ?>]" rows="2" maxlength="600" placeholder="<?= h($hint) ?>"><?= h((string) ($p['passport'][$key] ?? '')) ?></textarea></label>
            <?php endforeach; ?>
            <label class="narrow"><span>Asked my GP for a Reasonable Adjustment Flag on <span class="muted small">(optional)</span></span>
                <input type="date" name="raf_asked" max="<?= h($todayYmd) ?>" value="<?= h((string) $p['raf_asked']) ?>"></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
    <p class="small"><strong>Reasonable Adjustment Flag:</strong> <?= $p['raf_asked'] ? 'you asked your GP on ' . h(date('j M Y', strtotime($p['raf_asked']))) . '.'
        : 'you can ask your GP practice to add one to your NHS record, so NHS services know about the adjustments you need (such as written contact or a quiet wait).' ?></p>
    <?php if (array_filter($p['passport'])): ?>
    <details class="q-edit"><summary>Copy for an ICE / medical ID</summary>
        <label class="sr" for="passport-text">Passport text</label>
        <textarea id="passport-text" rows="6" readonly data-select-on-focus><?= h(passport_text($user, $p['passport'])) ?></textarea>
        <p class="muted small">Select it and copy it into your phone's medical ID or emergency information.</p>
    </details>
    <?php endif; ?>
</section>

<section class="card" id="nd-wait">
    <h2>Waiting lists</h2>
    <?php foreach ($waits as $w): ?>
    <div class="logline"><span><strong><?= h(ND_CONDITIONS[$w['assessment']] ?? $w['assessment']) ?> assessment</strong><?= $w['provider'] ? ' · ' . h($w['provider']) : '' ?>
        <?= $w['route'] ? '<span class="muted small"> · ' . h(ND_ROUTES[$w['route']]) . '</span>' : '' ?><br>
        <span class="small"><?= $w['referred_on'] ? ($w['assessed_on'] ? 'Waited ' . h(time_waited($w['referred_on'], $w['assessed_on'])) . ', assessed ' . h(date('j M Y', strtotime($w['assessed_on'])))
            : 'Waiting <strong>' . h(time_waited($w['referred_on'])) . '</strong> since ' . h(date('j M Y', strtotime($w['referred_on'])))) : '' ?></span>
        <?= $w['notes'] ? '<br><span class="muted small">' . h($w['notes']) . '</span>' : '' ?></span>
        <?php if ($canEdit): ?>
        <details class="q-edit"><summary class="btn btn-small">Update</summary>
            <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="nd_wait_update"><input type="hidden" name="wait_id" value="<?= (int) $w['id'] ?>">
                <label>Assessed on <input type="date" name="assessed_on" value="<?= h((string) $w['assessed_on']) ?>"></label>
                <label>Notes <input name="notes" maxlength="1000" value="<?= h((string) $w['notes']) ?>"></label>
                <span class="inline-actions"><button class="btn btn-small btn-primary" type="submit">Save</button></span></form>
            <form method="post" data-confirm="Remove this waiting list?"><?= csrf_field() ?><input type="hidden" name="action" value="nd_wait_delete"><input type="hidden" name="wait_id" value="<?= (int) $w['id'] ?>"><button class="btn-link danger small" type="submit">Remove</button></form>
        </details>
        <?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $waits ? '' : ' open' ?>><summary>Add a waiting list</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="nd_wait_add">
            <div class="grid-2">
                <label>Assessment for <select name="assessment"><?php foreach (ND_CONDITIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Route <select name="route"><option value="">&ndash;</option><?php foreach (ND_ROUTES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Provider <input name="provider" maxlength="150"></label>
                <label>Referred on <input type="date" name="referred_on" max="<?= h($todayYmd) ?>"></label>
            </div>
            <label>Notes <input name="notes" maxlength="1000"></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Add</button></span>
        </form>
    </details>
    <p class="muted small">In England, Right to Choose means you can ask your GP to refer you to an NHS-funded provider you choose, which can be quicker for ADHD and autism assessments.</p>
    <?php endif; ?>
</section>

<section class="card" id="nd-prep">
    <div class="card-head">
        <h2>Assessment prep</h2>
        <?php if ($prep): ?><a class="btn btn-small" href="<?= h(url('passport.php?show=prep')) ?>">Print</a><?php endif; ?>
    </div>
    <p class="small">Jot down examples as you think of them, so they're not forgotten on the day: from childhood and school, daily life, work, relationships, senses and your strengths.
        School reports or what family remember can help too.</p>
    <?php if ($canEdit): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="nd_prep_add">
        <label>About <select name="category"><?php foreach (ND_PREP_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label class="grow">Example <input name="note" maxlength="2000"></label>
        <button class="btn btn-small" type="submit">Add</button>
    </form>
    <?php endif; ?>
    <?php $cat = null; foreach ($prep as $n): if ($n['category'] !== $cat): $cat = $n['category']; ?><h3 class="preset-group"><?= h(ND_PREP_CATEGORIES[$cat] ?? $cat) ?></h3><?php endif; ?>
    <div class="logline"><span class="small"><?= nl2br(h($n['note'])) ?></span>
        <?php if ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="nd_prep_delete"><input type="hidden" name="prep_id" value="<?= (int) $n['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></div>
    <?php endforeach; ?>
</section>

<section class="card" id="nd-wellbeing">
    <h2>Energy and senses <span class="muted small">(optional)</span></h2>
    <?php if ($weekSpoons && array_sum($weekSpoons) / count($weekSpoons) <= 3 || count($weekSigns) >= 3): ?>
    <p class="flash flash-info small">This week looks hard going<?= $weekSigns ? ': ' . h(mb_strtolower(implode(', ', array_map(fn ($k) => ND_BURNOUT_SIGNS[$k] ?? $k, $weekSigns)))) : '' ?>.
        Those can be early signs of burnout. If you can, lower demands for a while, protect rest and quiet, and tell someone you trust. <a href="<?= h(url('help.php')) ?>">Help and support</a></p>
    <?php endif; ?>
    <?php $pts = array_map(fn ($l) => [strtotime($l['log_date'] . ' 12:00 UTC'), (float) $l['spoons']], array_filter($logs, fn ($l) => $l['spoons'] !== null)); if (count($pts) > 1): ?>
    <?= svg_line_chart([['label' => 'Energy (spoons)', 'unit' => '', 'points' => array_values($pts)]], strtotime($today->modify('-59 days')->format('Y-m-d') . ' UTC'), strtotime($todayYmd . ' 23:59 UTC'), 'Energy over the last 2 months') ?>
    <?php endif; ?>
    <?php if (cycle_anchors($uid)) {
        $lut = $oth = [];
        foreach ($logs as $l) {
            if ($l['spoons'] !== null) {
                is_luteal_day($uid, $l['log_date']) ? $lut[] = (int) $l['spoons'] : $oth[] = (int) $l['spoons'];
            }
        }
        if (count($lut) >= 3 && count($oth) >= 3) {
            echo '<p class="small">Energy in the 2 weeks before a period: ' . h(number_format(array_sum($lut) / count($lut), 1)) . ' on average, other days ' . h(number_format(array_sum($oth) / count($oth), 1)) . '.</p>';
        }
    } ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"><summary><?= $todayLog ? 'Update today' : 'Log today' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="nd_log">
            <div class="grid-2">
                <label>Date <input type="date" name="log_date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
                <label>Energy (spoons, 0 to 10) <input type="number" name="spoons" min="0" max="10" class="count-input" value="<?= h((string) ($todayLog['spoons'] ?? '')) ?>"></label>
                <label>Sensory load (0 calm to 5 overloaded) <input type="number" name="sensory" min="0" max="5" class="count-input" value="<?= h((string) ($todayLog['sensory'] ?? '')) ?>"></label>
                <label>Sleep (hours) <input type="number" name="sleep_hours" min="0" max="24" step="0.5" class="count-input" value="<?= h((string) ($todayLog['sleep_hours'] ?? '')) ?>"></label>
                <label>Meltdown or shutdown <select name="event"><option value="">None</option><option value="meltdown"<?= selected(($todayLog['event'] ?? '') === 'meltdown') ?>>Meltdown</option><option value="shutdown"<?= selected(($todayLog['event'] ?? '') === 'shutdown') ?>>Shutdown</option></select></label>
                <label>What might have contributed <input name="triggers" maxlength="300" value="<?= h((string) ($todayLog['triggers'] ?? '')) ?>" placeholder="e.g. noisy shop, plans changed, little sleep"></label>
            </div>
            <fieldset><legend>Noticed lately</legend><div class="checks"><?php foreach (ND_BURNOUT_SIGNS as $k => $l): ?>
                <label class="check"><input type="checkbox" name="burnout[]" value="<?= $k ?>"<?= checked(in_array($k, explode(',', (string) ($todayLog['burnout'] ?? '')), true)) ?>> <?= h($l) ?></label><?php endforeach; ?></div></fieldset>
            <label>Notes <input name="notes" maxlength="1000" value="<?= h((string) ($todayLog['notes'] ?? '')) ?>"></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
</section>
    <?php
}
