<?php
/*
 * Pregnancy options and aftercare: private, encrypted, never shared (see src/pathway.php).
 * The page title is just "Private", so browser history and tabs stay discreet.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
if (isset($user['_acting'])) {
    redirect('index.php'); // never shown to anyone with shared access (sharing.php doesn't list it either)
}
$uid = (int) $user['id'];
$today = local_today();
$todayYmd = $today->format('Y-m-d');
$record = pathway_record($uid);
$errors = [];

/** Local date and time from a form into UTC, or null. */
function pathway_when(string $date, string $time): ?string
{
    if (!parse_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time ?: '09:00')) {
        return null;
    }
    return local_to_utc($date . ' ' . ($time ?: '09:00'));
}

if (is_post()) {
    require_post();
    $action = post('action');

    if ($action === 'start' && !$record) {
        $pin = (string) ($_POST['pin'] ?? '');
        if ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) {
            $errors[] = 'A PIN is 4 to 8 digits (or leave it empty).';
        } else {
            pathway_save($uid, ['purpose' => isset(PATHWAY_PURPOSES[post('purpose')]) ? post('purpose') : 'options', 'steps' => [], 'meds' => [], 'symptoms' => [], 'reminders' => []]);
            if ($pin !== '') {
                q('UPDATE private_records SET pin_hash = ? WHERE user_id = ? AND kind = ?', [password_hash($pin, PASSWORD_DEFAULT), $uid, PATHWAY_KIND]);
                $_SESSION['pathway_unlocked'] = time();
            }
            redirect('pathway.php');
        }
    } elseif ($action === 'unlock' && $record) {
        if (login_is_throttled('#pathway' . $uid)) {
            $errors[] = 'Too many tries. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif ($record['pin_hash'] && password_verify((string) ($_POST['pin'] ?? ''), $record['pin_hash'])) {
            $_SESSION['pathway_unlocked'] = time();
            q('DELETE FROM login_attempts WHERE username = ?', ['#pathway' . $uid]);
            redirect('pathway.php');
        } else {
            q('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', [client_ip(), '#pathway' . $uid]);
            $errors[] = "That PIN isn't right.";
        }
    } elseif ($action === 'delete_all' && $record) {
        // Allowed even when locked (a forgotten PIN), as it only removes.
        pathway_delete($uid);
        if (post('switch_off') === '1') {
            $areas = array_diff(user_care_areas($user), ['pathway']);
            q('UPDATE users SET care_areas = ? WHERE id = ?', [implode(',', $areas), $uid]);
        }
        flash('success', 'Deleted. Nothing from it is left in Pillbox.');
        redirect(post('switch_off') === '1' ? 'care.php' : 'pathway.php');
    } elseif ($record && pathway_unlocked($record)) {
        $data = $record['data'];
        $steps = PATHWAY_STEPS[$data['purpose']] ?? PATHWAY_STEPS['options'];
        $anchor = '';
        switch ($action) {
            case 'lock':
                unset($_SESSION['pathway_unlocked']);
                redirect('care.php');
            case 'pin':
                $pin = (string) ($_POST['pin'] ?? '');
                if ($pin === '') {
                    q('UPDATE private_records SET pin_hash = NULL WHERE user_id = ? AND kind = ?', [$uid, PATHWAY_KIND]);
                    flash('success', 'PIN removed.');
                } elseif (preg_match('/^\d{4,8}$/', $pin)) {
                    q('UPDATE private_records SET pin_hash = ? WHERE user_id = ? AND kind = ?', [password_hash($pin, PASSWORD_DEFAULT), $uid, PATHWAY_KIND]);
                    $_SESSION['pathway_unlocked'] = time();
                    flash('success', 'PIN set. This page locks after ' . PATHWAY_UNLOCK_MINUTES . ' minutes without use.');
                } else {
                    flash('error', 'A PIN is 4 to 8 digits.');
                }
                redirect('pathway.php#privacy');
            case 'about':
                $data['purpose'] = isset(PATHWAY_PURPOSES[post('purpose')]) ? post('purpose') : $data['purpose'];
                if (post('dated_by') === 'weeks') {
                    $on = parse_date(post('weeks_on')) ?: $today;
                    $w = (int) post('weeks');
                    $d = min(6, max(0, (int) post('days')));
                    $data['start'] = $w >= 0 && $w <= 42 && (post('weeks') !== '') ? $on->modify('-' . ($w * 7 + $d) . ' days')->format('Y-m-d') : null;
                } else {
                    $lmp = parse_date(post('lmp'));
                    $data['start'] = $lmp && $lmp <= $today ? $lmp->format('Y-m-d') : null;
                }
                $data['provider'] = trim(mb_substr(post('provider'), 0, 150)) ?: null;
                $data['aftercare_phone'] = preg_replace('/[^0-9+ ]/', '', mb_substr(post('aftercare_phone'), 0, 40)) ?: null;
                $anchor = '#about';
                break;
            case 'step':
                $key = post('key');
                if (isset($steps[$key])) {
                    $day = parse_date(post('date'));
                    $time = post('time');
                    $data['steps'][$key] = [
                        'date' => $day?->format('Y-m-d'), 'time' => preg_match('/^\d\d:\d\d$/', $time) ? $time : null,
                        'how' => isset($steps[$key]['how'][post('how')]) ? post('how') : null,
                        'done' => post('done') === '1', 'note' => trim(mb_substr(post('note'), 0, 500)) ?: null,
                        'result' => in_array(post('result'), ['negative', 'positive', 'unclear'], true) ? post('result') : null,
                    ];
                    $at = $day ? pathway_when($day->format('Y-m-d'), $time) : null;
                    $data['reminders'] = array_values(array_filter($data['reminders'] ?? [], fn ($r) => ($r['ref'] ?? null) !== 'step-' . $key));
                    if (post('remind') === '1' && $at && $at > utc_now() && !$data['steps'][$key]['done']) {
                        pathway_add_reminder($data, $at, $steps[$key]['label'], 'step-' . $key);
                    }
                    // After treatment: the follow-up test about 3 weeks later, with a reminder.
                    if (in_array($key, ['treatment', 'management'], true) && $day && empty($data['steps']['test']['date'])) {
                        $testDay = $day->modify('+21 days')->format('Y-m-d');
                        $data['steps']['test'] = ['date' => $testDay, 'time' => '09:00', 'how' => null, 'done' => false, 'note' => null, 'result' => null];
                        if (($testAt = pathway_when($testDay, '09:00')) && $testAt > utc_now()) {
                            pathway_add_reminder($data, $testAt, 'Follow-up pregnancy test', 'step-test');
                        }
                    }
                }
                $anchor = '#step-' . $key;
                break;
            case 'med':
                $at = pathway_when(post('date') ?: $todayYmd, post('time'));
                $name = isset(PATHWAY_MEDICINES[post('name')]) ? post('name') : 'other';
                if (!$at) {
                    flash('error', 'Enter the date and time.');
                    redirect('pathway.php#meds');
                }
                $taken = post('taken') === '1' || $at <= utc_now();
                $id = bin2hex(random_bytes(6));
                $data['meds'][] = ['id' => $id, 'name' => $name, 'detail' => trim(mb_substr(post('detail'), 0, 150)) ?: null, 'at' => $at, 'taken' => $taken];
                if (!$taken) {
                    pathway_add_reminder($data, $at, PATHWAY_MEDICINES[$name], 'med-' . $id);
                }
                $anchor = '#meds';
                break;
            case 'med_taken':
                foreach ($data['meds'] ?? [] as $i => $m) {
                    if ($m['id'] === post('id')) {
                        $data['meds'][$i]['taken'] = true;
                        $data['meds'][$i]['at'] = $m['at'] <= utc_now() ? $m['at'] : utc_now(); // taken early: record when
                        $data['reminders'] = array_values(array_filter($data['reminders'] ?? [], fn ($r) => ($r['ref'] ?? null) !== 'med-' . $m['id']));
                    }
                }
                $anchor = '#meds';
                break;
            case 'symptom':
                $data['symptoms'][] = ['id' => bin2hex(random_bytes(6)), 'at' => utc_now(),
                    'bleeding' => isset(PATHWAY_BLEEDING[post('bleeding')]) ? post('bleeding') : null,
                    'pain' => post('pain') !== '' ? max(0, min(10, (int) post('pain'))) : null,
                    'note' => trim(mb_substr(post('note'), 0, 500)) ?: null];
                if (in_array(post('bleeding'), ['very_heavy'], true)) {
                    $_SESSION['pathway_urgent'] = true;
                }
                $anchor = post('bleeding') === 'very_heavy' ? '#call' : '#symptoms';
                break;
            case 'remove':
                // Remove one medicine or symptom entry (and any reminder for it).
                foreach (['meds', 'symptoms'] as $list) {
                    $data[$list] = array_values(array_filter($data[$list] ?? [], fn ($e) => $e['id'] !== post('id')));
                }
                $data['reminders'] = array_values(array_filter($data['reminders'] ?? [], fn ($r) => ($r['ref'] ?? null) !== 'med-' . post('id')));
                $anchor = '#' . (post('list') === 'meds' ? 'meds' : 'symptoms');
                break;
        }
        pathway_save($uid, $data);
        redirect('pathway.php' . $anchor);
    } else {
        redirect('pathway.php');
    }
}

$urgent = !empty($_SESSION['pathway_urgent']);
unset($_SESSION['pathway_urgent']);

/** Who to call: the provider's aftercare line (if saved), 999, NHS 111. */
function pathway_call_buttons(array $data): string
{
    $html = '';
    if (!empty($data['aftercare_phone'])) {
        $html .= '<a class="btn btn-primary" href="tel:' . h(str_replace(' ', '', $data['aftercare_phone'])) . '">Call ' . h($data['provider'] ?: 'your provider') . ' · ' . h($data['aftercare_phone']) . '</a>';
    }
    return '<span class="inline-actions">' . $html . '<a class="btn btn-danger" href="tel:999">Emergency: 999</a><a class="btn" href="tel:111">NHS 111</a></span>';
}

render_header('Private', 'care');
?>
<div class="page-head">
    <div>
        <p class="muted small"><a href="<?= h(url('care.php')) ?>">Care</a> &rsaquo;</p>
        <h1>&#x1F512; Pregnancy options and aftercare</h1>
    </div>
    <?php if ($record && $record['pin_hash'] && pathway_unlocked($record)): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lock"><button class="btn btn-small" type="submit">Lock now</button></form>
    <?php endif; ?>
</div>
<?= form_errors($errors) ?>

<?php if (!$record): ?>
<section class="card">
    <h2>Private by design</h2>
    <ul>
        <li>Everything here is encrypted, and can have its own PIN.</li>
        <li>It's never shared with anyone you share Pillbox with, and never appears on Today, in your calendar, in emails, reports or data downloads.</li>
        <li>Reminders only ever say "You have a reminder".</li>
        <li>You can delete it all in one go, at any time, leaving nothing behind.</li>
    </ul>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="start">
        <fieldset><legend>What's it for?</legend>
            <?php foreach (PATHWAY_PURPOSES as $k => $l): ?>
            <label class="check"><input type="radio" name="purpose" value="<?= $k ?>"<?= checked($k === 'options') ?>> <?= h($l) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label class="narrow"><span>PIN <span class="muted small">(optional, 4 to 8 digits)</span></span>
            <input type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" autocomplete="new-password"></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Start</button></span>
    </form>
</section>
<?php include_pathway_info(null, $today); ?>

<?php elseif (!pathway_unlocked($record)): ?>
<section class="card auth-card">
    <h2>Locked</h2>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="unlock">
        <label>PIN <input type="password" name="pin" inputmode="numeric" autocomplete="current-password" required autofocus></label>
        <button class="btn btn-primary btn-block" type="submit">Unlock</button>
    </form>
    <details class="q-edit"><summary>Forgotten the PIN?</summary>
        <p class="small">The only way back in is to delete everything on this page and start again.</p>
        <form method="post" data-confirm="Delete everything on this page? This can't be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
            <button class="btn btn-danger btn-small" type="submit">Delete everything</button></form>
    </details>
</section>

<?php else:
    $data = $record['data'];
    $purpose = $data['purpose'] ?? 'options';
    $steps = PATHWAY_STEPS[$purpose];
    $gest = pathway_gestation($data, $today);
    $referral = $data['steps']['referral']['date'] ?? null;
    $gestAtReferral = $referral ? pathway_gestation($data, new DateTimeImmutable($referral)) : null;
    $pending = array_filter($data['reminders'] ?? [], fn ($r) => empty($r['sent']));
    usort($pending, fn ($a, $b) => $a['at'] <=> $b['at']); ?>

<section class="card preg-call<?= $urgent ? ' urgent' : '' ?>" id="call">
    <?php if ($urgent): ?>
    <h2>Call now</h2>
    <p><strong>Bleeding this heavy needs checking straight away.</strong> Call your provider's 24-hour aftercare line, or 999 if you feel faint or very unwell.</p>
    <?php else: ?>
    <h2>When to get help</h2>
    <p>Call your provider's 24-hour aftercare line (or 999 in an emergency) if you have:</p>
    <?php endif; ?>
    <ul><?php foreach (PATHWAY_RED_FLAGS as $flag): ?><li><?= h($flag) ?></li><?php endforeach; ?></ul>
    <?= pathway_call_buttons($data) ?>
    <p class="small">Struggling emotionally? Your safety plan and people to talk to (Samaritans, 116 123, any time) are in <a href="<?= h(url('help.php')) ?>">Help</a>.</p>
    <?php if (empty($data['aftercare_phone'])): ?><p class="muted small">Add your provider's aftercare number under <a href="#about">About</a> so it's here when you need it.</p><?php endif; ?>
</section>

<?php if ($pending): ?>
<section class="card">
    <h2>Reminders</h2>
    <ul class="plain"><?php foreach ($pending as $r): ?>
        <li class="logline"><span><strong><?= h(local_time($r['at'], 'D j M, H:i')) ?></strong> · <?= h($r['label']) ?></span></li>
    <?php endforeach; ?></ul>
    <p class="muted small">On your devices these just say "You have a reminder" (turn on notifications in Account settings).</p>
</section>
<?php endif; ?>

<section class="card" id="about">
    <h2>About</h2>
    <?php if ($gest): ?>
    <p><strong><?= $gest[0] ?> weeks <?= $gest[1] ?> days</strong> today<?= $gestAtReferral ? ' · ' . $gestAtReferral[0] . ' weeks ' . $gestAtReferral[1] . ' days when referred' : '' ?></p>
    <?php endif; ?>
    <details class="q-edit"<?= $gest ? '' : ' open' ?>><summary><?= $gest ? 'Change' : 'How many weeks, and who to call' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="about">
            <fieldset><legend>What's it for?</legend>
                <?php foreach (PATHWAY_PURPOSES as $k => $l): ?>
                <label class="check"><input type="radio" name="purpose" value="<?= $k ?>"<?= checked($k === $purpose) ?>> <?= h($l) ?></label>
                <?php endforeach; ?>
            </fieldset>
            <fieldset><legend>How many weeks</legend>
                <div class="repeat-choice">
                    <label class="check"><input type="radio" name="dated_by" value="lmp" id="dated-lmp" checked> From my last period</label>
                    <label class="check"><input type="radio" name="dated_by" value="weeks" id="dated-weeks"> I was told (e.g. at a scan)</label>
                </div>
                <label class="narrow" data-show-when="dated-lmp">First day of your last period <input type="date" name="lmp" max="<?= h($todayYmd) ?>" value="<?= h((string) ($data['start'] ?? '')) ?>"></label>
                <div class="grid-2" data-show-when="dated-weeks">
                    <label>Weeks <input type="number" name="weeks" min="0" max="42" class="count-input"></label>
                    <label>and days <input type="number" name="days" min="0" max="6" class="count-input"></label>
                    <label>on <input type="date" name="weeks_on" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
                </div>
            </fieldset>
            <div class="grid-2">
                <label>Provider or clinic <input name="provider" maxlength="150" value="<?= h((string) ($data['provider'] ?? '')) ?>"></label>
                <label>24-hour aftercare number <input type="tel" name="aftercare_phone" maxlength="40" value="<?= h((string) ($data['aftercare_phone'] ?? '')) ?>"></label>
            </div>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
        </form>
    </details>
</section>

<?php if ($purpose === 'options') { include_pathway_info($gest, $today); } ?>

<section class="card" id="steps">
    <h2>Steps</h2>
    <p class="muted small">Dates here stay on this page: they aren't added to your appointments or calendar. Tick "Remind me" for a discreet reminder.</p>
    <?php foreach ($steps as $key => $step): $s = $data['steps'][$key] ?? []; ?>
    <div class="preg-item<?= !empty($s['done']) ? ' state-done' : '' ?>" id="step-<?= h($key) ?>">
        <div class="preg-item-main"><div>
            <strong><?= h($step['label']) ?></strong>
            <?= !empty($s['done']) ? '<span class="badge badge-taken">Done</span>' : (!empty($s['date']) ? '<span class="badge">' . h(date('j M', strtotime($s['date']))) . (!empty($s['time']) ? ' ' . h($s['time']) : '') . '</span>' : '') ?>
            <?php if (!empty($s['how'])): ?><span class="muted small"><?= h($step['how'][$s['how']]) ?></span><?php endif; ?>
            <?php if (!empty($s['result'])): ?><span class="small<?= $s['result'] !== 'negative' ? ' warn' : '' ?>"> · <?= h(['negative' => 'Negative', 'positive' => 'Positive', 'unclear' => 'Unclear'][$s['result']]) ?></span><?php endif; ?>
            <?php if (!empty($step['tip'])): ?><div class="small"><?= h($step['tip']) ?></div><?php endif; ?>
            <?php if (!empty($s['note'])): ?><div class="small muted"><?= h($s['note']) ?></div><?php endif; ?>
            <?php if (($s['result'] ?? null) && $s['result'] !== 'negative'): ?><div class="small warn">Contact your provider or GP: a positive or unclear test this long afterwards needs checking.</div><?php endif; ?>
        </div></div>
        <details class="q-edit"><summary class="btn btn-small">Update</summary>
            <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="step"><input type="hidden" name="key" value="<?= h($key) ?>">
                <div class="grid-2">
                    <label>Date <input type="date" name="date" value="<?= h((string) ($s['date'] ?? '')) ?>"></label>
                    <label><span>Time <span class="muted small">(optional)</span></span><input type="time" name="time" value="<?= h((string) ($s['time'] ?? '')) ?>"></label>
                    <?php if (!empty($step['how'])): ?>
                    <label>How <select name="how"><option value="">&ndash;</option><?php foreach ($step['how'] as $k => $l): ?><option value="<?= $k ?>"<?= selected(($s['how'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                    <?php if (!empty($step['test'])): ?>
                    <label>Result <select name="result"><option value="">&ndash;</option><?php foreach (['negative' => 'Negative', 'positive' => 'Positive', 'unclear' => 'Unclear'] as $k => $l): ?><option value="<?= $k ?>"<?= selected(($s['result'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                </div>
                <label>Note <input name="note" maxlength="500" value="<?= h((string) ($s['note'] ?? '')) ?>"></label>
                <label class="check"><input type="checkbox" name="done" value="1"<?= checked(!empty($s['done'])) ?>> Done</label>
                <label class="check"><input type="checkbox" name="remind" value="1"<?= checked((bool) array_filter($pending, fn ($r) => ($r['ref'] ?? '') === 'step-' . $key)) ?>> Remind me at that time</label>
                <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
            </form>
        </details>
    </div>
    <?php endforeach; ?>
</section>

<section class="card" id="meds">
    <h2>Medicines</h2>
    <p class="muted small">Take medicines exactly as your provider told you. The second medicine is usually taken 24 to 48 hours after the first.
        Add one with a time in the future to be reminded.</p>
    <?php $meds = $data['meds'] ?? []; usort($meds, fn ($a, $b) => $b['at'] <=> $a['at']); if ($meds): ?>
    <ul class="plain"><?php foreach ($meds as $m): ?>
        <li class="logline"><span><strong><?= h(local_time($m['at'], 'D j M, H:i')) ?></strong> · <?= h(PATHWAY_MEDICINES[$m['name']] ?? 'Medicine') ?><?= $m['detail'] ? ' · ' . h($m['detail']) : '' ?>
            <?= $m['taken'] ? '<span class="badge badge-taken">Taken</span>' : '<span class="badge badge-upcoming">To take</span>' ?></span>
            <span class="inline-actions">
                <?php if (!$m['taken']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="med_taken"><input type="hidden" name="id" value="<?= h($m['id']) ?>"><button class="btn btn-small btn-primary" type="submit">Taken</button></form><?php endif; ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="list" value="meds"><input type="hidden" name="id" value="<?= h($m['id']) ?>"><button class="btn-link danger small" type="submit">Remove</button></form>
            </span></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="med">
        <label>Medicine <select name="name"><?php foreach (PATHWAY_MEDICINES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Details <input name="detail" maxlength="150" placeholder="e.g. 2 paracetamol"></label>
        <label>Date <input type="date" name="date" value="<?= h($todayYmd) ?>"></label>
        <label>Time <input type="time" name="time" value="<?= h(local_time(utc_now(), 'H:i')) ?>"></label>
        <button class="btn btn-small" type="submit">Add</button>
    </form>
</section>

<section class="card" id="symptoms">
    <h2>Bleeding and symptoms</h2>
    <p class="muted small">Bleeding and cramps can last up to 2 weeks, sometimes longer, and usually get lighter.</p>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="symptom">
        <label>Bleeding <select name="bleeding"><option value="">&ndash;</option><?php foreach (PATHWAY_BLEEDING as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Pain (0 to 10) <input type="number" name="pain" min="0" max="10" class="count-input"></label>
        <label>Notes <input name="note" maxlength="500"></label>
        <button class="btn btn-small" type="submit">Add</button>
    </form>
    <?php $symptoms = $data['symptoms'] ?? []; usort($symptoms, fn ($a, $b) => $b['at'] <=> $a['at']); if ($symptoms): ?>
    <ul class="plain"><?php foreach (array_slice($symptoms, 0, 40) as $sy): ?>
        <li class="logline"><span><strong><?= h(local_time($sy['at'], 'D j M, H:i')) ?></strong>
            <?= $sy['bleeding'] ? ' · ' . h(PATHWAY_BLEEDING[$sy['bleeding']]) : '' ?><?= $sy['pain'] !== null ? ' · pain ' . (int) $sy['pain'] . '/10' : '' ?><?= $sy['note'] ? ' · ' . h($sy['note']) : '' ?></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="list" value="symptoms"><input type="hidden" name="id" value="<?= h($sy['id']) ?>"><button class="btn-link danger small" type="submit">Remove</button></form></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section class="card" id="privacy">
    <h2>Privacy</h2>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="pin">
        <label><?= $record['pin_hash'] ? 'New PIN' : 'PIN' ?> <span class="muted small">(4 to 8 digits<?= $record['pin_hash'] ? '; empty removes it' : '' ?>)</span>
            <input type="password" name="pin" inputmode="numeric" autocomplete="new-password"></label>
        <button class="btn btn-small" type="submit"><?= $record['pin_hash'] ? 'Change PIN' : 'Set a PIN' ?></button>
    </form>
    <form method="post" class="stack" data-confirm="Delete everything on this page? This can't be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
        <label class="check"><input type="checkbox" name="switch_off" value="1" checked> Also remove this page from my Care categories</label>
        <span class="inline-actions"><button class="btn btn-danger" type="submit">Delete everything</button></span>
        <p class="muted small">Removes it all from Pillbox straight away. Copies in your server's own database backups, if it has any, are outside Pillbox.</p>
    </form>
</section>
<?php endif; ?>
<?php render_footer();

/** Options, by gestation, without recommending any of them; how to get help and support. */
function include_pathway_info(?array $gest, DateTimeImmutable $today): void
{
    ?>
<section class="card" id="options">
    <h2>Your options</h2>
    <p>It's your decision, and there's no right or wrong choice. You can take time to think, and talk it through with someone if you'd like.</p>
    <div class="options-grid">
        <div><h3>Continuing the pregnancy</h3>
            <p class="small">Tell a midwife or GP. <a href="<?= h(url('pregnancy.php')) ?>">Pillbox's Pregnancy page</a> tracks antenatal care.</p></div>
        <div><h3>Adoption</h3>
            <p class="small">Your midwife or GP can put you in touch with your council's adoption service. You don't have to decide until after the birth.
                <a href="https://www.gov.uk/child-adoption" target="_blank" rel="noopener">GOV.UK: adoption</a></p></div>
        <div><h3>Abortion</h3>
            <p class="small">Contact a provider yourself (confidential), or ask your GP or a sexual health clinic to refer you.
                <a href="https://www.nhs.uk/conditions/abortion/" target="_blank" rel="noopener">NHS: abortion</a></p></div>
    </div>
    <h3>Abortion: what's usually available</h3>
    <ul class="plain options-weeks">
        <?php foreach (PATHWAY_OPTIONS_BY_WEEK as [$from, $to, $label, $text]): $here = $gest && $gest[0] >= $from && $gest[0] <= $to; ?>
        <li class="logline<?= $here ? ' current' : '' ?>"><span><strong><?= h($label) ?></strong><?= $here ? ' <span class="badge">You are here</span>' : '' ?><br><span class="small"><?= h($text) ?></span></span></li>
        <?php endforeach; ?>
    </ul>
    <p class="muted small">In England, Scotland and Wales, abortion is legal up to 24 weeks, but each service sets its own limits, so contact one early.
        The law in Northern Ireland is different: check with a provider. The provider will confirm what's possible for you.</p>
    <h3>Providers and support</h3>
    <p class="small"><a href="https://www.bpas.org" target="_blank" rel="noopener">BPAS</a> ·
        <a href="https://www.msichoices.org.uk" target="_blank" rel="noopener">MSI Reproductive Choices</a> ·
        <a href="https://www.nupas.co.uk" target="_blank" rel="noopener">NUPAS</a> ·
        <a href="https://www.brook.org.uk" target="_blank" rel="noopener">Brook</a> (under 25s) ·
        <a href="https://www.miscarriageassociation.org.uk" target="_blank" rel="noopener">Miscarriage Association</a> ·
        <a href="https://www.tommys.org" target="_blank" rel="noopener">Tommy's</a></p>
    <p class="muted small">Abortion providers offer free counselling before or after, whatever you decide.</p>
</section>
    <?php
}
