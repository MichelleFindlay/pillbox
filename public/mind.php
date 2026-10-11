<?php
/*
 * Mind: check in (mood, energy, motivation, anxiety, tags, triggers, note), see
 * trends and patterns, and get to the toolkit, journal, questionnaires and safety plan.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
seed_crisis_contacts($uid);
$today = local_today();
$errors = [];

if (is_post()) {
    require_post();
    switch (post('action')) {
        case 'checkin':
            [$c, $errors] = checkin_from_post($today);
            if ($c) {
                q('INSERT INTO mind_checkins (user_id, logged_at, log_date, mood, energy, motivation, anxiety, tags, triggers, note)
                   VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $today->format('Y-m-d'), $c['mood'], $c['energy'], $c['motivation'], $c['anxiety'], $c['tags'], $c['triggers'], $c['note']]);
                $checkinId = (int) db()->lastInsertId();
                foreach (mind_symptoms($uid) as $s) {
                    $v = $_POST['symptom'][$s['id']] ?? '';
                    if (is_string($v) && ctype_digit($v) && (int) $v <= 10) {
                        q('INSERT INTO mind_symptom_logs (checkin_id, symptom_id, severity) VALUES (?, ?, ?)', [$checkinId, $s['id'], (int) $v]);
                    }
                }
                if (post('unsafe') === '1') {
                    redirect('help.php?checkin=1');
                }
                if ($c['mood'] <= 2 || ($c['anxiety'] ?? 0) >= 8) {
                    redirect('mind.php?after=low#toolkit');
                }
                flash('success', 'Check-in saved.');
                redirect('mind.php');
            }
            break;
        case 'selfcare':
            $item = post('item');
            if (in_array($item, selfcare_items($user), true)) {
                post('done') === '1'
                    ? q('INSERT IGNORE INTO mind_selfcare (user_id, log_date, item) VALUES (?, ?, ?)', [$uid, $today->format('Y-m-d'), $item])
                    : q('DELETE FROM mind_selfcare WHERE user_id = ? AND log_date = ? AND item = ?', [$uid, $today->format('Y-m-d'), $item]);
            }
            redirect('mind.php#today');
        case 'selfcare_items':
            $items = array_slice(array_filter(array_map(fn ($l) => trim(mb_substr($l, 0, 60)), explode("\n", str_replace("\r", '', post('items'))))), 0, 12);
            q('UPDATE users SET mind_selfcare = ? WHERE id = ?', [$items ? implode("\n", $items) : null, $uid]);
            flash('success', 'Self-care checklist updated.');
            redirect('mind.php#today');
        case 'contacted':
            q('UPDATE mind_contacts SET last_contact = ? WHERE id = ? AND user_id = ?', [$today->format('Y-m-d'), (int) post('id'), $uid]);
            redirect('mind.php#today');
        case 'delete_checkin':
            q('DELETE FROM mind_checkins WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            flash('success', 'Check-in deleted.');
            redirect('mind.php#recent');
        case 'set_pin':
        case 'remove_pin':
        case 'settings':
        case 'remove_device':
            owner_only('Changing Mind privacy settings');
    }
    switch (post('action')) {
        case 'set_pin':
            $pin = post('pin');
            if (!preg_match('/^\d{4,8}$/', $pin)) {
                flash('error', 'Use 4 to 8 digits for your PIN.');
            } elseif ($pin !== post('pin_confirm')) {
                flash('error', "The PINs don't match.");
            } else {
                q('UPDATE users SET mind_pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $uid]);
                $_SESSION['mind_unlocked_at'] = time();
                flash('success', 'PIN set. Mind locks after 10 minutes without using it.');
            }
            redirect('mind.php#privacy');
        case 'remove_device':
            q('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            flash('success', 'Device removed.');
            redirect('mind.php#privacy');
        case 'remove_pin':
            q('DELETE FROM webauthn_credentials WHERE user_id = ?', [$uid]);
            q('UPDATE users SET mind_pin_hash = NULL WHERE id = ?', [$uid]);
            flash('success', 'PIN removed.');
            redirect('mind.php#privacy');
        case 'settings':
            $time = post('reminder');
            q('UPDATE users SET mind_lock_plan = ?, mind_reminder = ? WHERE id = ?',
                [post('lock_plan') === '1' ? 1 : 0, preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $time) ? substr($time, 0, 5) : null, $uid]);
            flash('success', 'Settings saved.');
            redirect('mind.php#privacy');
    }
}

$range = in_array(get('range'), ['30', '90'], true) ? (int) get('range') : 30;
$from = $today->modify('-' . ($range - 1) . ' days');
$checkins = mind_checkins($uid, $from->format('Y-m-d'), $today->format('Y-m-d'));
$daily = mind_daily($checkins);
$todays = array_filter($checkins, fn ($c) => $c['log_date'] === $today->format('Y-m-d'));
$insights = mind_insights($user, $today);
$toolkit = q('SELECT * FROM mind_toolkit WHERE user_id = ? ORDER BY id', [$uid])->fetchAll();
$symptoms = mind_symptoms($uid);
$ratings = symptom_ratings(array_column($checkins, 'id'));
$symptomNames = array_column(mind_symptoms($uid, false), 'name', 'id');
$selfcare = selfcare_items($user);
$selfcareDone = q('SELECT item FROM mind_selfcare WHERE user_id = ? AND log_date = ?', [$uid, $today->format('Y-m-d')])->fetchAll(PDO::FETCH_COLUMN);
$todaysActivities = q("SELECT * FROM mind_activities WHERE user_id = ? AND planned_for = ? ORDER BY planned_time IS NULL, planned_time", [$uid, $today->format('Y-m-d')])->fetchAll();
$reachOut = reach_out_due($uid, $today);
$lowSpell = low_spell($uid, $today);
$amberPlan = $lowSpell ? plan_text($uid, ['amber_me']) : [];
$wins = get('after') === 'low' ? q("SELECT text FROM mind_notes WHERE user_id = ? AND kind = 'win' ORDER BY RAND() LIMIT 3", [$uid])->fetchAll(PDO::FETCH_COLUMN) : [];
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
$fromTs = $from->getTimestamp();
$toTs = $today->modify('+1 day')->getTimestamp();

render_header('Mind', 'mind');
?>
<div class="page-head">
    <div>
        <h1>Mind <?= help_link('mind', '', 'Mind') ?></h1>
        <p class="muted">Check in whenever you like. There's no right or wrong way to use this.</p>
    </div>
    <span class="inline-actions">
        <a class="btn btn-small" href="<?= h(url('mind_summary.php')) ?>">Summary</a>
        <a class="btn btn-small btn-help" href="<?= h(url('help.php')) ?>">Help now</a>
    </span>
</div>

<?php if (get('after') === 'low'): ?>
<section class="card card-gentle" id="toolkit">
    <h2>Thanks for checking in</h2>
    <p>It sounds like things are hard right now. Here are some things that have helped you before:</p>
    <?php if ($toolkit): ?>
    <ul class="toolkit-list">
        <?php foreach ($toolkit as $t): ?><li><?= h($t['item']) ?></li><?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="muted small">You haven't added anything to your coping toolkit yet. <a href="<?= h(url('mind_toolkit.php')) ?>">Add the things that help you</a>,
        so they're here next time.</p>
    <?php endif; ?>
    <?php if ($wins): ?>
    <p>And some good things you've noted before:</p>
    <ul class="toolkit-list"><?php foreach ($wins as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <span class="inline-actions">
        <a class="btn btn-primary" href="<?= h(url('breathe.php#breathe')) ?>">Breathing exercise</a>
        <a class="btn" href="<?= h(url('breathe.php#ground')) ?>">5-4-3-2-1 grounding</a>
        <a class="btn" href="<?= h(url('help.php')) ?>">People to talk to</a>
    </span>
</section>
<?php endif; ?>

<section class="card" id="checkin">
    <h2>How are you?</h2>
    <?= form_errors($errors) ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="checkin">
        <fieldset>
            <legend>Mood</legend>
            <div class="mood-choice">
                <?php foreach (MOODS as $n => [$face, $label]): ?>
                <label class="mood-option"><input type="radio" name="mood" value="<?= $n ?>" required<?= checked(post('mood') === (string) $n) ?>>
                    <span><span class="mood-face" aria-hidden="true"><?= $face ?></span><?= h($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="grid-2">
            <?php foreach (['energy' => 'Energy', 'motivation' => 'Motivation'] as $f => $label): ?>
            <label><?= $label ?> <span class="muted small">(optional)</span>
                <select name="<?= $f ?>"><option value="">&ndash;</option>
                    <?php foreach (LEVELS as $n => $l): ?><option value="<?= $n ?>"<?= selected(post($f) === (string) $n) ?>><?= $n ?> &middot; <?= h($l) ?></option><?php endforeach; ?>
                </select></label>
            <?php endforeach; ?>
            <label>Anxiety or stress <span class="muted small">(optional, 0 = none, 10 = worst)</span>
                <select name="anxiety"><option value="">&ndash;</option>
                    <?php for ($n = 0; $n <= 10; $n++): ?><option<?= selected(post('anxiety') === (string) $n) ?>><?= $n ?></option><?php endfor; ?>
                </select></label>
            <label>Triggers <span class="muted small">(optional: what's been going on)</span>
                <input name="triggers" maxlength="500" value="<?= h(post('triggers')) ?>" placeholder="e.g. work deadline, poor sleep"></label>
        </div>
        <fieldset>
            <legend>Words that fit <span class="muted small">(optional)</span></legend>
            <div class="days tag-choice">
                <?php foreach (MIND_TAGS as $tag): ?>
                <label class="day"><input type="checkbox" name="tags[]" value="<?= h($tag) ?>"<?= checked(in_array($tag, (array) ($_POST['tags'] ?? []), true)) ?>><span><?= h($tag) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php if ($symptoms): ?>
        <fieldset>
            <legend>Symptoms <span class="muted small">(0 = none, 10 = worst; <a href="<?= h(url('mind_symptoms.php')) ?>">change</a>)</span></legend>
            <div class="grid-2">
                <?php foreach ($symptoms as $s): ?>
                <label><?= h($s['name']) ?><select name="symptom[<?= (int) $s['id'] ?>]"><option value="">&ndash;</option>
                    <?php for ($n = 0; $n <= 10; $n++): ?><option<?= selected(($_POST['symptom'][$s['id']] ?? '') === (string) $n) ?>><?= $n ?></option><?php endfor; ?></select></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endif; ?>
        <label>Note <span class="muted small">(optional)</span><textarea name="note" rows="3" maxlength="5000"><?= h(post('note')) ?></textarea></label>
        <label class="check unsafe-check"><input type="checkbox" name="unsafe" value="1"> <span>I'm having thoughts of harming myself or ending my life</span></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save check-in</button></span>
    </form>
    <?php if ($todays): ?>
    <p class="muted small">Today: <?= h(implode(', ', array_map(fn ($c) => local_time($c['logged_at'], 'H:i') . ' ' . MOODS[$c['mood']][1], array_reverse($todays)))) ?></p>
    <?php endif; ?>
</section>

<?php if ($lowSpell): ?>
<section class="card card-gentle">
    <h2>The last few days have been hard</h2>
    <?php if (!empty($amberPlan['amber_me'])): ?>
    <p>Your staying well plan says that when things start slipping, you'll:</p>
    <p class="plan-text"><strong><?= nl2br(h($amberPlan['amber_me'])) ?></strong></p>
    <?php else: ?>
    <p>It might help to tell someone how you're feeling, or to look at what's helped before.</p>
    <?php endif; ?>
    <span class="inline-actions">
        <a class="btn" href="<?= h(url('mind_relapse.php')) ?>">Staying well plan</a>
        <a class="btn" href="<?= h(url('mind_care.php#questions')) ?>">Note it for my next appointment</a>
        <a class="btn btn-help" href="<?= h(url('help.php')) ?>">Help now</a>
    </span>
</section>
<?php endif; ?>

<section class="card" id="today">
    <h2>Today</h2>
    <h3>Self-care basics</h3>
    <div class="selfcare">
        <?php foreach ($selfcare as $item): $done = in_array($item, $selfcareDone, true); ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="selfcare"><input type="hidden" name="item" value="<?= h($item) ?>"><input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
            <button class="btn btn-small<?= $done ? ' btn-primary' : '' ?>" type="submit" aria-pressed="<?= $done ? 'true' : 'false' ?>"><?= $done ? '&#10003; ' : '' ?><?= h($item) ?></button></form>
        <?php endforeach; ?>
    </div>
    <details class="q-edit"><summary class="small">Change the list</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="selfcare_items">
            <label><span class="muted small">One per line, up to 12</span><textarea name="items" rows="6"><?= h(implode("\n", $selfcare)) ?></textarea></label>
            <span class="inline-actions"><button class="btn btn-small" type="submit">Save</button></span></form>
    </details>
    <?php if ($todaysActivities): ?>
    <h3>Planned for today</h3>
    <ul class="plain"><?php foreach ($todaysActivities as $a): ?>
        <li class="logline"><span><?= $a['planned_time'] ? '<strong>' . h(substr($a['planned_time'], 0, 5)) . '</strong> ' : '' ?><?= h($a['activity']) ?></span>
            <?= $a['status'] === 'planned' ? '<a class="btn btn-small" href="' . h(url('mind_activities.php')) . '">How did it go?</a>' : '<span class="badge badge-taken">Done</span>' ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($reachOut): ?>
    <h3>Get in touch</h3>
    <ul class="plain"><?php foreach ($reachOut as $c): ?>
        <li class="logline"><span><strong><?= h($c['name']) ?></strong> <span class="muted small"><?= $c['since'] === null ? 'not noted yet' : 'last ' . ($c['since'] < 14 ? $c['since'] . ' days ago' : round($c['since'] / 7) . ' weeks ago') ?></span></span>
            <span class="inline-actions"><?= contact_links($c) ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="contacted"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-small" type="submit">We were in touch</button></form></span></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<div class="mind-links">
    <a class="care-tile" href="<?= h(url('mind_toolkit.php')) ?>"><span class="care-tile-title">&#x1F9F0; Coping toolkit</span><span class="muted small">Your list of what helps, breathing and grounding</span></a>
    <?php if (!isset($user['_acting']) || $user['_acting']['perms']['journal']): ?>
    <a class="care-tile" href="<?= h(url('mind_journal.php')) ?>"><span class="care-tile-title">&#x1F4D3; Journal</span><span class="muted small">Free writing, prompts and thought records</span></a>
    <?php endif; ?>
    <a class="care-tile" href="<?= h(url('mind_check.php')) ?>"><span class="care-tile-title">&#x1F4CB; Questionnaires</span><span class="muted small">PHQ-9 (depression) and GAD-7 (anxiety)</span></a>
    <a class="care-tile" href="<?= h(url('mind_plan.php')) ?>"><span class="care-tile-title">&#x1F6DF; Safety plan</span><span class="muted small">Warning signs, coping, people and contacts</span></a>
    <a class="care-tile" href="<?= h(url('mind_care.php')) ?>"><span class="care-tile-title">&#x1F91D; Therapy and care</span><span class="muted small">Sessions, actions agreed, questions for next time, care team</span></a>
    <a class="care-tile" href="<?= h(url('mind_meds.php')) ?>"><span class="care-tile-title">&#x1F48A; Medication</span><span class="muted small">Side effects and how you've felt since a change</span></a>
    <a class="care-tile" href="<?= h(url('mind_activities.php')) ?>"><span class="care-tile-title">&#x1F5D3;&#xFE0F; Activities</span><span class="muted small">Plan small things, then rate how they went</span></a>
    <a class="care-tile" href="<?= h(url('care.php?area=mind')) ?>"><span class="care-tile-title">&#x1F331; Habits and routines</span><span class="muted small">Morning and evening routines, screen-time limits</span></a>
    <a class="care-tile" href="<?= h(url('mind_symptoms.php')) ?>"><span class="care-tile-title">&#x1F4C8; Symptoms</span><span class="muted small">Your own symptoms, rated when you check in</span></a>
    <a class="care-tile" href="<?= h(url('mind_wins.php')) ?>"><span class="care-tile-title">&#x2728; Wins and gratitude</span><span class="muted small">Small positives, for the harder days</span></a>
    <a class="care-tile" href="<?= h(url('mind_relapse.php')) ?>"><span class="care-tile-title">&#x1F6A6; Staying well plan</span><span class="muted small">Early warning signs, and what to do at each stage</span></a>
    <a class="care-tile" href="<?= h(url('mind_advance.php')) ?>"><span class="care-tile-title">&#x1F4DD; Advance statement</span><span class="muted small">Your wishes for care if you become unwell</span></a>
</div>

<section class="card" id="trends">
    <div class="card-head">
        <h2>Trends</h2>
        <nav class="chips" aria-label="Period">
            <?php foreach ([30, 90] as $r): ?><a class="chip" href="<?= h(url("mind.php?range=$r#trends")) ?>"<?= $r === $range ? ' aria-current="page"' : '' ?>><?= $r ?> days</a><?php endforeach; ?>
        </nav>
    </div>
    <?php if (!$daily): ?>
    <p class="muted">Your check-ins will show here as a chart.</p>
    <?php else: ?>
    <h3>Mood, energy and motivation <span class="muted small">(1 to 5, daily average)</span></h3>
    <?= svg_line_chart([
        ['label' => 'Mood', 'unit' => '', 'points' => $points('mood')],
        ['label' => 'Energy', 'unit' => '', 'points' => $points('energy')],
        ['label' => 'Motivation', 'unit' => '', 'points' => $points('motivation')],
    ], $fromTs, $toTs, 'Mood, energy and motivation') ?>
    <?php if ($points('anxiety')): ?>
    <h3>Anxiety or stress <span class="muted small">(0 to 10)</span></h3>
    <?= svg_line_chart([['label' => 'Anxiety', 'unit' => '', 'points' => $points('anxiety')]], $fromTs, $toTs, 'Anxiety') ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($insights): ?>
    <h3>Patterns</h3>
    <ul class="insights">
        <?php foreach ($insights as $i): ?><li><?= h($i) ?></li><?php endforeach; ?>
    </ul>
    <p class="muted small">From the last 90 days. These are patterns, not causes, but they can be useful to talk about at appointments.</p>
    <?php elseif ($daily): ?>
    <p class="muted small">After a few weeks of check-ins, Pillbox looks for patterns, such as how sleep or activity go with your mood.</p>
    <?php endif; ?>
</section>

<?php if ($checkins): ?>
<section class="card" id="recent">
    <h2>Recent check-ins</h2>
    <ul class="plain">
    <?php foreach (array_slice($checkins, 0, 10) as $c): ?>
        <li class="logline checkin-row">
            <span><span class="mood-face small-face" aria-hidden="true"><?= MOODS[$c['mood']][0] ?></span>
                <strong><?= h(local_time($c['logged_at'], 'D j M, H:i')) ?></strong> &middot; <?= h(MOODS[$c['mood']][1]) ?>
                <span class="muted small"><?= implode(' · ', array_filter([
                    $c['energy'] ? 'energy ' . (int) $c['energy'] . '/5' : null,
                    $c['motivation'] ? 'motivation ' . (int) $c['motivation'] . '/5' : null,
                    $c['anxiety'] !== null ? 'anxiety ' . (int) $c['anxiety'] . '/10' : null,
                    $c['tags'] ? h(str_replace(',', ', ', $c['tags'])) : null,
                    ...array_map(fn ($sid, $v) => h(($symptomNames[$sid] ?? 'Symptom') . " $v/10"), array_keys($ratings[$c['id']] ?? []), $ratings[$c['id']] ?? []),
                ])) ?></span>
                <?php if ($c['triggers']): ?><br><span class="small">Triggers: <?= h($c['triggers']) ?></span><?php endif; ?>
                <?php if ($c['note']): ?><br><span class="small"><?= nl2br(h($c['note'])) ?></span><?php endif; ?></span>
            <form method="post" data-confirm="Delete this check-in?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_checkin"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn-link danger" type="submit">Delete</button>
            </form>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!isset($user['_acting'])): ?>
<section class="card" id="privacy">
    <h2>Privacy and reminders</h2>
    <?php if ($user['mind_pin_hash']): ?>
    <p>Mind is locked with a PIN. It locks again after 10 minutes without using it.</p>
    <?php $devices = q('SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(); ?>
    <?php if ($devices): ?>
    <p class="small">A passkey for unlocking Mind is set up on:</p>
    <ul class="plain">
        <?php foreach ($devices as $d): ?>
        <li class="logline"><span><?= h($d['label']) ?> <span class="muted small">added <?= h(local_time($d['created_at'], 'j M Y')) ?><?= $d['last_used_at'] ? ', last used ' . h(local_time($d['last_used_at'], 'j M')) : '' ?></span></span>
            <form method="post" data-confirm="Remove the passkey for <?= h($d['label']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="remove_device"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="btn-link danger small" type="submit">Remove</button></form></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="muted small">A passkey lets you unlock Mind with your fingerprint, face or your device's own PIN, instead of typing your Mind PIN.</p>
    <p class="inline-actions">
        <button class="btn btn-small" type="button" hidden data-webauthn-register data-csrf="<?= h(csrf_token()) ?>">Add a passkey on this device</button>
        <span class="small warn" data-webauthn-status></span>
    </p>
    <form method="post" class="inline-actions" data-confirm="Remove the PIN from Mind?">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_pin">
        <button class="btn btn-small" type="submit">Remove PIN</button>
    </form>
    <?php else: ?>
    <p class="muted small">Add a PIN so someone using your phone or computer while you're signed in can't open Mind. Crisis contacts stay available without it.</p>
    <?php endif; ?>
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_pin">
        <label><?= $user['mind_pin_hash'] ? 'New PIN' : 'PIN' ?> <input type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" minlength="4" maxlength="8" autocomplete="new-password" required></label>
        <label>Again <input type="password" name="pin_confirm" inputmode="numeric" pattern="\d{4,8}" minlength="4" maxlength="8" autocomplete="new-password" required></label>
        <button class="btn" type="submit"><?= $user['mind_pin_hash'] ? 'Change PIN' : 'Set PIN' ?></button>
    </form>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings">
        <label class="check"><input type="checkbox" name="lock_plan" value="1"<?= checked((bool) $user['mind_lock_plan']) ?>>
            <span>Keep my safety plan behind the PIN too <span class="muted small">(crisis numbers are always shown)</span></span></label>
        <label class="narrow"><span>Daily check-in reminder <span class="muted small">(optional; in your calendar feed as "Pillbox check-in", so it doesn't say what it's for)</span></span>
            <input type="time" name="reminder" value="<?= h($user['mind_reminder'] ? substr($user['mind_reminder'], 0, 5) : '') ?>"></label>
        <span class="inline-actions"><button class="btn" type="submit">Save</button></span>
    </form>
</section>
<?php endif; ?>
<?php render_footer();
