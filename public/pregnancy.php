<?php
/*
 * Pregnancy (a Care category): due date and gestation, the antenatal timeline, check-ups,
 * screening results, movements and notes, and when to call the maternity unit.
 * It complements the maternity unit's own notes and never interprets results.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
$todayYmd = $today->format('Y-m-d');
if (!share_area_allowed('pregnancy')) {
    flash('error', "That part of {$user['display_name']}'s Pillbox hasn't been shared with you.");
    redirect('care.php');
}
$preg = active_pregnancy($uid);
$errors = [];
$details = null; // the details form, when it has errors

/** Read the details form (due date and contacts). @return array{0: array, 1: string[]} */
function pregnancy_details_from_post(DateTimeImmutable $today): array
{
    $errors = [];
    $method = post('due_method') === 'lmp' ? 'lmp' : 'scan';
    $lmp = $method === 'lmp' ? parse_date(post('lmp_date')) : null;
    $due = $method === 'lmp' ? ($lmp ? $lmp->modify('+280 days') : null) : parse_date(post('due_date'));
    if ($method === 'lmp' && (!$lmp || $lmp > $today || $lmp < $today->modify('-44 weeks'))) {
        $errors[] = 'Enter the first day of your last period (within the last 44 weeks).';
    } elseif ($method === 'scan' && (!$due || $due < $today->modify('-4 weeks') || $due > $today->modify('+42 weeks'))) {
        $errors[] = 'Enter your due date.';
    }
    $phone = fn (string $f) => preg_replace('/[^0-9+ ]/', '', mb_substr(post($f), 0, 40)) ?: null;
    return [[
        'due_date' => $due?->format('Y-m-d'), 'due_source' => $method, 'lmp_date' => $lmp?->format('Y-m-d'),
        'first_baby' => post('first_baby') === '0' ? 0 : 1,
        'rh_negative' => ['yes' => 1, 'no' => 0][post('rh_negative')] ?? null,
        'maternity_unit' => trim(mb_substr(post('maternity_unit'), 0, 150)) ?: null, 'maternity_phone' => $phone('maternity_phone'),
        'midwife' => trim(mb_substr(post('midwife'), 0, 150)) ?: null, 'midwife_phone' => $phone('midwife_phone'),
    ], $errors];
}

if (is_post()) {
    require_post();
    $action = post('action');
    if ($action === 'start' || $action === 'details') {
        [$details, $errors] = pregnancy_details_from_post($today);
        if (!$errors) {
            $cols = array_keys($details);
            if ($action === 'details' && $preg) {
                q('UPDATE pregnancies SET ' . implode(', ', array_map(fn ($c) => "$c = ?", $cols)) . ' WHERE id = ?', [...array_values($details), $preg['id']]);
                flash('success', 'Details saved. Your due date is ' . date('j F Y', strtotime($details['due_date'])) . '.');
            } elseif (!$preg) {
                q('INSERT INTO pregnancies (user_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')', [$uid, ...array_values($details)]);
                flash('success', 'Congratulations. Your timeline is below; tick things off as you go.');
            }
            redirect('pregnancy.php');
        }
    } elseif (!$preg) {
        redirect('pregnancy.php');
    } else {
        $timeline = pregnancy_timeline($preg, $today);
        $item = $timeline[post('key')] ?? null;
        switch ($action) {
            case 'item_done':
                $day = parse_date(post('date')) ?: $today;
                if ($item && $day <= $today) {
                    q('INSERT INTO pregnancy_items (pregnancy_id, item_key, done_on, result) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE done_on = VALUES(done_on), result = COALESCE(VALUES(result), result)',
                        [$preg['id'], $item['key'], $day->format('Y-m-d'), trim(mb_substr(post('result'), 0, 500)) ?: null]);
                    flash('success', $item['label'] . ': done.' . ($item['key'] === 'dating_scan' && $preg['due_source'] !== 'scan'
                        ? ' If the scan gave you a new due date, update it under Details.' : ''));
                }
                redirect('pregnancy.php#item-' . post('key'));
            case 'item_result':
                if ($item) {
                    q('INSERT INTO pregnancy_items (pregnancy_id, item_key, result) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE result = VALUES(result)',
                        [$preg['id'], $item['key'], trim(mb_substr(post('result'), 0, 500)) ?: null]);
                    flash('success', 'Result saved.');
                }
                redirect('pregnancy.php#item-' . post('key'));
            case 'item_undo':
                if ($item) {
                    q('UPDATE pregnancy_items SET done_on = NULL WHERE pregnancy_id = ? AND item_key = ?', [$preg['id'], $item['key']]);
                }
                redirect('pregnancy.php#item-' . post('key'));
            case 'check_add':
                $day = parse_date(post('checked_on'));
                $num = fn (string $f, float $min, float $max) => is_numeric(post($f)) && (float) post($f) >= $min && (float) post($f) <= $max ? (float) post($f) : null;
                $sys = $num('bp_sys', 60, 260);
                $dia = $num('bp_dia', 30, 160);
                if (!$day || $day > $today) {
                    $errors[] = 'Enter the date of the appointment.';
                }
                if (($sys === null) !== ($dia === null)) {
                    $errors[] = 'Enter both blood pressure numbers (e.g. 118 over 76), or neither.';
                }
                if (!$errors) {
                    q('INSERT INTO antenatal_checks (pregnancy_id, user_id, checked_on, bp_sys, bp_dia, urine, fundal_cm, heartbeat, position, notes, questions)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                        $preg['id'], $uid, $day->format('Y-m-d'), $sys, $dia,
                        isset(URINE_RESULTS[post('urine')]) ? post('urine') : null, $num('fundal_cm', 5, 60),
                        ['yes' => 1, 'no' => 0][post('heartbeat')] ?? null, isset(BABY_POSITIONS[post('position')]) ? post('position') : null,
                        trim(mb_substr(post('notes'), 0, 2000)) ?: null, trim(mb_substr(post('questions'), 0, 2000)) ?: null,
                    ]);
                    sync_antenatal_bp($uid, (int) db()->lastInsertId(), $sys ? (int) $sys : null, $dia ? (int) $dia : null, $day->format('Y-m-d'));
                    flash(antenatal_bp_flag($sys ? (int) $sys : null, $dia ? (int) $dia : null) ? 'error' : 'success', antenatal_bp_flag($sys ? (int) $sys : null, $dia ? (int) $dia : null)
                        ? 'Check-up saved. Your blood pressure was ' . (int) $sys . '/' . (int) $dia . ': if your midwife hasn\'t already talked to you about it, call them or your maternity unit today.'
                        : 'Check-up saved.');
                    redirect('pregnancy.php#checkups');
                }
                break;
            case 'check_delete':
                $c = q('SELECT id FROM antenatal_checks WHERE id = ? AND pregnancy_id = ?', [(int) post('check_id'), $preg['id']])->fetch();
                if ($c) {
                    sync_antenatal_bp($uid, (int) $c['id'], null, null, $todayYmd);
                    q('DELETE FROM antenatal_checks WHERE id = ?', [$c['id']]);
                }
                redirect('pregnancy.php#checkups');
            case 'movements':
                q("INSERT INTO pregnancy_log (pregnancy_id, user_id, logged_at, kind, note) VALUES (?, ?, UTC_TIMESTAMP(), 'movements', ?)",
                    [$preg['id'], $uid, trim(mb_substr(post('note'), 0, 1000)) ?: 'Movements felt different']);
                $_SESSION['pregnancy_call_now'] = true;
                redirect('pregnancy.php#call');
            case 'note':
                if (trim(post('note')) !== '') {
                    q("INSERT INTO pregnancy_log (pregnancy_id, user_id, logged_at, kind, note) VALUES (?, ?, UTC_TIMESTAMP(), 'note', ?)",
                        [$preg['id'], $uid, trim(mb_substr(post('note'), 0, 1000))]);
                }
                redirect('pregnancy.php#notes');
            case 'log_delete':
                q('DELETE FROM pregnancy_log WHERE id = ? AND pregnancy_id = ?', [(int) post('log_id'), $preg['id']]);
                redirect('pregnancy.php#notes');
            case 'supplement':
                // Folic acid until 12 weeks; vitamin D throughout (and while breastfeeding).
                if (!share_can('doses', 'edit')) {
                    redirect('pregnancy.php#supplements');
                }
                $folic = post('which') === 'folic';
                $twelve = pregnancy_start($preg)->modify('+84 days')->modify('-1 day');
                if ($folic && $twelve < $today) {
                    redirect('pregnancy.php#supplements');
                }
                $medId = transaction(function () use ($uid, $folic, $twelve, $todayYmd) {
                    q('INSERT INTO medications (user_id, name, strength, form, dose_unit, colour, reason, start_date, end_date, course) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                        $uid, $folic ? 'Folic acid' : 'Vitamin D', $folic ? '400 micrograms' : '10 micrograms', 'tablet', 'tablet', $folic ? 'yellow' : 'orange',
                        $folic ? 'Pregnancy, until 12 weeks' : 'Pregnancy and breastfeeding', $todayYmd,
                        $folic ? $twelve->format('Y-m-d') : null, $folic ? 'short' : 'ongoing',
                    ]);
                    $medId = (int) db()->lastInsertId();
                    q('INSERT INTO medication_schedules (medication_id, dose_time, dose_amount, days_mask) VALUES (?, ?, 1, ?)', [$medId, '08:00:00', EVERY_DAY]);
                    record_med_change($uid, $medId, $todayYmd, 'started', 'Started ' . ($folic ? '400 micrograms' : '10 micrograms') . ', 1 tablet a day');
                    return $medId;
                });
                flash('success', ($folic ? 'Folic acid' : 'Vitamin D') . ' added to your medicines, 1 tablet each morning'
                    . ($folic ? ' until ' . $twelve->format('j F') : '') . '. You can change the time on the Meds page.');
                redirect('pregnancy.php#supplements');
            case 'end':
                $day = parse_date(post('ended_on'));
                if ($day && $day <= $today) {
                    $status = post('outcome') === 'born' ? 'born' : 'ended';
                    q('UPDATE pregnancies SET status = ?, ended_on = ? WHERE id = ?', [$status, $day->format('Y-m-d'), $preg['id']]);
                    flash('success', $status === 'born' ? 'Congratulations. Your pregnancy record is kept below.' : 'Your pregnancy record has been closed and kept below.');
                    if ($status === 'ended') {
                        $_SESSION['pregnancy_support'] = true;
                    }
                }
                redirect('pregnancy.php');
        }
    }
}

$past = q("SELECT * FROM pregnancies WHERE user_id = ? AND status <> 'active' ORDER BY id DESC", [$uid])->fetchAll();
$canEdit = share_can('care', 'edit');
$callNow = !empty($_SESSION['pregnancy_call_now']);
$showSupport = !empty($_SESSION['pregnancy_support']);
unset($_SESSION['pregnancy_call_now'], $_SESSION['pregnancy_support']);

/** The "who to call" buttons (maternity unit, midwife, 999). */
function pregnancy_call_buttons(?array $p): string
{
    $html = '';
    if ($p && $p['maternity_phone']) {
        $html .= '<a class="btn btn-primary" href="tel:' . h(str_replace(' ', '', $p['maternity_phone'])) . '">Call ' . h($p['maternity_unit'] ?: 'the maternity unit') . ' · ' . h($p['maternity_phone']) . '</a>';
    }
    if ($p && $p['midwife_phone']) {
        $html .= '<a class="btn" href="tel:' . h(str_replace(' ', '', $p['midwife_phone'])) . '">Call ' . h($p['midwife'] ?: 'your midwife') . '</a>';
    }
    $html .= '<a class="btn btn-danger" href="tel:999">Emergency: 999</a>';
    return '<span class="inline-actions">' . $html . '</span>';
}

/** The details form: due date (from last period or the scan), first baby, Rhesus, contacts. */
function pregnancy_details_fields(array $v, DateTimeImmutable $today): void
{
    $method = $v['due_source'] ?? 'lmp'; ?>
    <fieldset>
        <legend>Your due date</legend>
        <div class="repeat-choice">
            <label class="check"><input type="radio" name="due_method" value="lmp" id="due-lmp"<?= checked($method === 'lmp') ?>> Work it out from my last period</label>
            <label class="check"><input type="radio" name="due_method" value="scan" id="due-scan"<?= checked($method !== 'lmp') ?>> I know it (from my dating scan or midwife)</label>
        </div>
        <label class="narrow" data-show-when="due-lmp">First day of your last period
            <input type="date" name="lmp_date" max="<?= h($today->format('Y-m-d')) ?>" value="<?= h((string) ($v['lmp_date'] ?? '')) ?>"></label>
        <label class="narrow" data-show-when="due-scan">Due date <input type="date" name="due_date" value="<?= h((string) ($v['due_date'] ?? '')) ?>"></label>
        <p class="muted small">The NHS uses the date from your dating scan (at 11 to 14 weeks), so update it here once you've had it.</p>
    </fieldset>
    <div class="grid-2">
        <label>First baby?
            <select name="first_baby"><option value="1"<?= selected((int) ($v['first_baby'] ?? 1) === 1) ?>>Yes</option><option value="0"<?= selected(isset($v['first_baby']) && (int) $v['first_baby'] === 0) ?>>No, I've had a baby before</option></select></label>
        <label>Rhesus (blood group) negative?
            <select name="rh_negative"><?php foreach (['' => "Don't know yet", 'no' => 'No', 'yes' => 'Yes'] as $k => $l): ?>
                <option value="<?= $k ?>"<?= selected(($v['rh_negative'] ?? null) === null ? $k === '' : ((int) $v['rh_negative'] === 1 ? $k === 'yes' : $k === 'no')) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Maternity unit <input name="maternity_unit" maxlength="150" value="<?= h((string) ($v['maternity_unit'] ?? '')) ?>" placeholder="e.g. Royal County maternity"></label>
        <label><span>Maternity unit or triage phone <span class="muted small">(for urgent worries)</span></span>
            <input type="tel" name="maternity_phone" maxlength="40" value="<?= h((string) ($v['maternity_phone'] ?? '')) ?>"></label>
        <label>Midwife <input name="midwife" maxlength="150" value="<?= h((string) ($v['midwife'] ?? '')) ?>"></label>
        <label>Midwife's phone <input type="tel" name="midwife_phone" maxlength="40" value="<?= h((string) ($v['midwife_phone'] ?? '')) ?>"></label>
    </div>
    <?php
}

render_header('Pregnancy', 'care');
?>
<div class="page-head">
    <div>
        <p class="muted small"><a href="<?= h(url('care.php')) ?>">Care</a> &rsaquo;</p>
        <h1>&#x1F930; Pregnancy <?= help_link('pregnancy', '', 'Pregnancy') ?></h1>
    </div>
</div>
<?= form_errors($errors) ?>

<?php if ($showSupport): ?>
<section class="card">
    <p>We're sorry. If you'd like to talk to someone, your midwife or GP can help, and so can
        <a href="https://www.tommys.org" target="_blank" rel="noopener">Tommy's</a>,
        the <a href="https://www.miscarriageassociation.org.uk" target="_blank" rel="noopener">Miscarriage Association</a> and
        <a href="https://www.sands.org.uk" target="_blank" rel="noopener">Sands</a>.</p>
</section>
<?php endif; ?>

<?php if (!$preg): ?>
<section class="card">
    <h2>Set up your pregnancy</h2>
    <p class="muted">Add your due date to get the NHS antenatal timeline: appointments, scans, vaccines and forms, week by week.</p>
    <p class="muted small">Not sure yet what you want to do? <a href="<?= h(url('pathway.php')) ?>">Pregnancy options</a> covers all your options, privately.</p>
    <?php if ($canEdit): ?>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="start">
        <?php pregnancy_details_fields($details ?? [], $today); ?>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Start</button></span>
    </form>
    <?php endif; ?>
</section>
<?php else:
    $timeline = pregnancy_timeline($preg, $today);
    [$weeks, $days] = gestation($preg, $today);
    $toGo = (int) $today->diff(new DateTimeImmutable($preg['due_date']))->format('%r%a');
    $next = pregnancy_next($timeline);
    $checks = q('SELECT * FROM antenatal_checks WHERE pregnancy_id = ? ORDER BY checked_on DESC, id DESC', [$preg['id']])->fetchAll();
    $log = q('SELECT * FROM pregnancy_log WHERE pregnancy_id = ? ORDER BY logged_at DESC LIMIT 30', [$preg['id']])->fetchAll();
    $appts = q("SELECT * FROM appointments WHERE user_id = ? AND kind IN ('antenatal', 'scan') AND starts_at >= ? ORDER BY starts_at",
        [$uid, pregnancy_start($preg)->format('Y-m-d')])->fetchAll();
    $apptTitles = array_map(fn ($a) => mb_strtolower((string) $a['title']), $appts);
    $meds = user_medications($uid);
    $hasMed = fn (string $name) => (bool) array_filter($meds, fn ($m) => stripos($m['name'], $name) !== false); ?>

<section class="card preg-head">
    <div>
        <p class="preg-gestation"><?= $weeks ?> <span>week<?= $weeks === 1 ? '' : 's' ?></span><?= $days ? ' ' . $days . ' <span>day' . ($days === 1 ? '' : 's') . '</span>' : '' ?></p>
        <p class="muted">Trimester <?= trimester($preg, $today) ?> · due <strong><?= h(date('l j F Y', strtotime($preg['due_date']))) ?></strong>
            <?= $toGo > 0 ? "($toGo days to go)" : ($toGo === 0 ? '(today)' : '(' . -$toGo . ' days past)') ?>
            <span class="small">· <?= $preg['due_source'] === 'scan' ? 'from your scan' : 'estimated from your last period' ?></span></p>
        <progress class="preg-progress" max="40" value="<?= min(40, $weeks + $days / 7) ?>" aria-label="Weeks of pregnancy"><?= $weeks ?> of 40 weeks</progress>
    </div>
    <?php if ($next): ?>
    <p class="preg-next">Next: <a href="#item-<?= h($next['key']) ?>"><strong><?= h($next['label']) ?></strong></a>
        <span class="muted small"><?= $next['state'] === 'now' ? 'now (' . h(pregnancy_weeks_label($next)) . ')' : h(pregnancy_weeks_label($next)) . ', from ' . h($next['from_date']->format('j M')) ?></span></p>
    <?php endif; ?>
</section>

<?php if ($bpPregMeds = array_filter(user_medications($uid), fn ($m) => in_array(bp_class($m['name']), ['acei', 'arb'], true) || preg_match('/spironolactone|atenolol/i', $m['name']))): ?>
<p class="flash flash-warning">Some blood pressure medicines (including <?= h(implode(', ', array_map(fn ($m) => $m['name'], $bpPregMeds))) ?>) aren't recommended in pregnancy: contact your GP straight away for a medication review. Don't just stop taking it.</p>
<?php endif; ?>
<?php if (has_diabetes($user)): ?>
<p class="flash flash-warning">You have diabetes: tell your diabetes team you're pregnant straight away. You'll usually need a higher dose of folic acid (5 mg), tighter glucose targets and extra scans.</p>
<?php endif; ?>
<?php if ($thyroidMeds = thyroid_meds(user_medications($uid))): ?>
<p class="flash flash-warning">You take <?= h($thyroidMeds[0]['name']) ?>: if you haven't already, tell your GP you're pregnant now. The dose usually needs increasing early in pregnancy, and your TSH will be checked more often.</p>
<?php endif; ?>
<section class="card preg-call<?= $callNow ? ' urgent' : '' ?>" id="call">
    <?php if ($callNow): ?>
    <h2>Call your maternity unit now</h2>
    <p><strong>Don't wait until tomorrow, and don't rely on a home monitor or app.</strong> A change in your baby's movements needs checking straight away, day or night.
        Your maternity unit expects these calls.</p>
    <?php else: ?>
    <h2>Something not right?</h2>
    <p>Call your maternity unit straight away, day or night, if:</p>
    <ul><?php foreach (PREGNANCY_RED_FLAGS as $flag): ?><li><?= h($flag) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?= pregnancy_call_buttons($preg) ?>
    <?php if (!$preg['maternity_phone']): ?><p class="muted small">Add your maternity unit's number under <a href="#details">Details</a> so it's here when you need it. If you don't have it, call NHS 111.</p><?php endif; ?>
    <?php if (!$callNow && $canEdit): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="movements">
        <label class="sr" for="mv-note">What's different (optional)</label><input id="mv-note" name="note" maxlength="1000" placeholder="What's different (optional)">
        <button class="btn" type="submit">Baby's movements feel different</button></form>
    <p class="small">Feeling low or anxious? Your midwife can help, and your safety plan and crisis lines are in <a href="<?= h(url('help.php')) ?>">Help</a>.</p>
    <p class="muted small">There's no set number of kicks to count: what matters is your baby's usual pattern. Movements don't slow down towards the end.</p>
    <?php endif; ?>
</section>

<section class="card" id="timeline">
    <h2>Your antenatal timeline</h2>
    <p class="muted small">The usual NHS pattern<?= $preg['first_baby'] ? ' for a first baby' : '' ?>. Your maternity team may add extra appointments, scans or clinics:
        <a href="<?= h(url('appointment_edit.php?kind=antenatal')) ?>">add them to your appointments</a>.</p>
    <?php $tri = 0; foreach ($timeline as $key => $it):
        $t = $it['from'] < 13 ? 1 : ($it['from'] < 27 ? 2 : 3);
        if ($t !== $tri): $tri = $t; ?><h3 class="preset-group"><?= ['', 'First trimester', 'Second trimester', 'Third trimester'][$t] ?></h3><?php endif;
        $booked = array_filter($appts, fn ($a) => mb_strtolower((string) $a['title']) === mb_strtolower($it['label'])); ?>
    <div class="preg-item state-<?= h($it['state']) ?><?= !empty($it['optional']) ? ' optional' : '' ?>" id="item-<?= h($key) ?>">
        <div class="preg-item-main">
            <span class="preg-icon" aria-hidden="true"><?= PREGNANCY_KIND_ICONS[$it['kind']] ?></span>
            <div>
                <strong><?= h($it['label']) ?></strong>
                <?= $it['state'] === 'done' ? '<span class="badge badge-taken">Done ' . h(date('j M', strtotime($it['record']['done_on']))) . '</span>'
                    : ($it['state'] === 'now' ? '<span class="badge badge-due">Now</span>' : '') ?>
                <div class="muted small"><?= h(ucfirst(pregnancy_weeks_label($it))) ?> · <?= h($it['from_date']->format('j M')) ?> to <?= h($it['to_date']->format('j M')) ?>
                    <?php foreach ($booked as $a): ?> · <a href="<?= h(url('appointment.php?id=' . $a['id'])) ?>">booked <?= h(local_time($a['starts_at'], 'D j M, H:i')) ?></a><?php endforeach; ?></div>
                <div class="small"><?= h($it['tip']) ?></div>
                <?php if (!empty($it['result']) && ($it['record']['result'] ?? null)): ?><div class="small preg-result">Result: <?= h($it['record']['result']) ?></div><?php endif; ?>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <div class="preg-item-actions">
            <?php if ($it['state'] === 'done'): ?>
                <?php if (!empty($it['result'])): ?>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="item_result"><input type="hidden" name="key" value="<?= h($key) ?>">
                    <label class="sr" for="res-<?= h($key) ?>">Results</label><input id="res-<?= h($key) ?>" name="result" maxlength="500" value="<?= h((string) ($it['record']['result'] ?? '')) ?>" placeholder="Results, as you were told them">
                    <button class="btn btn-small" type="submit">Save</button></form>
                <?php endif; ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="item_undo"><input type="hidden" name="key" value="<?= h($key) ?>">
                    <button class="btn-link small" type="submit">Undo</button></form>
            <?php else: ?>
                <?php if (in_array($it['kind'], ['appointment', 'scan', 'vaccine'], true) && !$booked && $it['state'] !== 'past'): ?>
                <a class="btn btn-small" href="<?= h(url('appointment_edit.php?' . http_build_query(['kind' => $it['kind'] === 'vaccine' ? 'vaccination' : ($it['kind'] === 'scan' ? 'scan' : 'antenatal'),
                    'title' => $it['label'], 'date' => max($todayYmd, $it['from_date']->format('Y-m-d'))]))) ?>">Book</a>
                <?php endif; ?>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="item_done"><input type="hidden" name="key" value="<?= h($key) ?>">
                    <label class="sr" for="d-<?= h($key) ?>">Date</label><input id="d-<?= h($key) ?>" type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>">
                    <?php if (!empty($it['result'])): ?><label class="sr" for="r-<?= h($key) ?>">Results</label><input id="r-<?= h($key) ?>" name="result" maxlength="500" placeholder="Results (optional)"><?php endif; ?>
                    <button class="btn btn-small btn-primary" type="submit">Done</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>

<?php if ($appts): ?>
<section class="card">
    <div class="card-head"><h2>Your antenatal appointments</h2><a class="btn btn-small" href="<?= h(url('appointment_edit.php?kind=antenatal')) ?>">+ Add</a></div>
    <ul class="plain"><?php foreach ($appts as $a): ?>
        <li class="logline"><span><strong><?= h(local_time($a['starts_at'], 'D j M, H:i')) ?></strong> · <a href="<?= h(url('appointment.php?id=' . $a['id'])) ?>"><?= h(appointment_title($a)) ?></a>
            <span class="muted small"><?= h(gestation_label($preg, new DateTimeImmutable(local_time($a['starts_at'], 'Y-m-d')), true)) ?> weeks<?= $a['with_whom'] ? ' · ' . h($a['with_whom']) : '' ?></span></span></li>
    <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<section class="card" id="checkups">
    <h2>Check-ups</h2>
    <p class="muted small">What was checked at each appointment, for your own record. Pillbox doesn't interpret these: your midwife will tell you if anything needs looking at.</p>
    <?php $lastQ = array_values(array_filter($checks, fn ($c) => $c['questions']))[0]['questions'] ?? null;
    if ($lastQ): ?><p class="flash flash-info"><strong>To ask next time:</strong> <?= nl2br(h($lastQ)) ?></p><?php endif; ?>
    <?php if ($checks): ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Weeks</th><th>Blood pressure</th><th>Urine</th><th class="num">Bump (cm)</th><th>Heartbeat</th><th>Position</th><th>Notes</th><th><span class="sr">Delete</span></th></tr></thead>
        <tbody><?php foreach ($checks as $c): $flag = antenatal_bp_flag($c['bp_sys'] !== null ? (int) $c['bp_sys'] : null, $c['bp_dia'] !== null ? (int) $c['bp_dia'] : null); ?>
            <tr><td><?= h(date('j M', strtotime($c['checked_on']))) ?></td><td><?= h(gestation_label($preg, new DateTimeImmutable($c['checked_on']), true)) ?></td>
                <td<?= $flag ? ' class="warn"' : '' ?>><?= $c['bp_sys'] ? (int) $c['bp_sys'] . '/' . (int) $c['bp_dia'] . ($flag ? ' &#9888;' : '') : '–' ?></td>
                <td><?= h(URINE_RESULTS[$c['urine']] ?? '–') ?></td><td class="num"><?= $c['fundal_cm'] !== null ? h(num($c['fundal_cm'])) : '–' ?></td>
                <td><?= $c['heartbeat'] === null ? '–' : ((int) $c['heartbeat'] ? 'Heard' : 'Not heard') ?></td><td><?= h(BABY_POSITIONS[$c['position']] ?? '–') ?></td>
                <td class="small"><?= nl2br(h((string) $c['notes'])) ?></td>
                <td><?php if ($canEdit): ?><form method="post" data-confirm="Delete this check-up?"><?= csrf_field() ?><input type="hidden" name="action" value="check_delete"><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>">
                    <button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php if (array_filter($checks, fn ($c) => antenatal_bp_flag($c['bp_sys'] !== null ? (int) $c['bp_sys'] : null, $c['bp_dia'] !== null ? (int) $c['bp_dia'] : null))): ?>
    <p class="small warn">&#9888; marks a blood pressure of 140/90 or more. If your midwife hasn't talked to you about it, call them or your maternity unit, especially with a headache, vision problems or swelling.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $checks ? '' : ' open' ?>><summary>Add a check-up</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="check_add">
            <div class="grid-2">
                <label>Date <input type="date" name="checked_on" required max="<?= h($todayYmd) ?>" value="<?= h(post('checked_on') ?: $todayYmd) ?>"></label>
                <fieldset class="bp-pair"><legend>Blood pressure</legend>
                    <input type="number" name="bp_sys" min="60" max="260" inputmode="numeric" aria-label="Top number" placeholder="118" class="count-input"> /
                    <input type="number" name="bp_dia" min="30" max="160" inputmode="numeric" aria-label="Bottom number" placeholder="76" class="count-input"></fieldset>
                <label>Urine test <select name="urine"><option value="">–</option><?php foreach (URINE_RESULTS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Bump measurement (cm) <input type="number" name="fundal_cm" min="5" max="60" step="0.5" inputmode="decimal"></label>
                <label>Baby's heartbeat <select name="heartbeat"><option value="">–</option><option value="yes">Heard</option><option value="no">Not heard</option></select></label>
                <label><span>Baby's position <span class="muted small">(from about 36 weeks)</span></span>
                    <select name="position"><option value="">–</option><?php foreach (BABY_POSITIONS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <label>Notes <textarea name="notes" rows="2" maxlength="2000"></textarea></label>
            <label>Questions to ask next time <textarea name="questions" rows="2" maxlength="2000"></textarea></label>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save check-up</button></span>
            <p class="muted small">Blood pressure is also added to your Readings, so you can see it on the chart.</p>
        </form>
    </details>
    <?php endif; ?>
</section>

<section class="card" id="supplements">
    <h2>Supplements and looking after yourself</h2>
    <ul class="plain">
        <li class="logline"><span><strong>Folic acid</strong>, 400 micrograms a day until 12 weeks <span class="muted small">(your GP may prescribe a higher dose)</span></span>
            <?php if ($hasMed('folic')): ?><span class="muted small">In your medicines</span>
            <?php elseif ($weeks < 12 && $canEdit && share_can('doses', 'edit')): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="supplement"><input type="hidden" name="which" value="folic"><button class="btn btn-small" type="submit">Add to my medicines</button></form><?php endif; ?></li>
        <li class="logline"><span><strong>Vitamin D</strong>, 10 micrograms a day, in pregnancy and while breastfeeding</span>
            <?php if ($hasMed('vitamin d')): ?><span class="muted small">In your medicines</span>
            <?php elseif ($canEdit && share_can('doses', 'edit')): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="supplement"><input type="hidden" name="which" value="vitamin_d"><button class="btn btn-small" type="submit">Add to my medicines</button></form><?php endif; ?></li>
    </ul>
    <p class="small"><a href="<?= h(url('readings.php')) ?>">Log your weight or symptoms</a> in Readings<?= mind_enabled($user) ? ', and how you\'re feeling in <a href="' . h(url('mind_check.php')) . '">a Mind check-in</a>' : '' ?>.
        Before taking any other medicine, check with a pharmacist, midwife or GP that it's safe in pregnancy.</p>
</section>

<section class="card" id="notes">
    <h2>Notes and movements</h2>
    <?php if ($canEdit): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="note">
        <label class="sr" for="p-note">Note</label><input id="p-note" name="note" maxlength="1000" placeholder="A note: symptoms, how you're feeling, something to remember">
        <button class="btn btn-small" type="submit">Add</button></form>
    <?php endif; ?>
    <?php if ($log): ?>
    <ul class="plain"><?php foreach ($log as $l): ?>
        <li class="logline"><span><strong><?= h(local_time($l['logged_at'], 'j M, H:i')) ?></strong>
            <?= $l['kind'] === 'movements' ? '<span class="badge badge-due">Movements</span>' : '' ?> <?= h((string) $l['note']) ?></span>
            <?php if ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="log_delete"><input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                <button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></li>
    <?php endforeach; ?></ul>
    <?php else: ?><p class="muted small">Nothing yet.</p><?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" id="details">
    <details class="q-edit"<?= $details ? ' open' : '' ?>><summary>Details: due date, maternity unit and midwife</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="details">
            <?php pregnancy_details_fields($details ?? $preg, $today); ?>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
        </form>
    </details>
    <details class="q-edit"><summary>Baby's arrived, or the pregnancy has ended</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="end">
            <div class="repeat-choice">
                <label class="check"><input type="radio" name="outcome" value="born" checked> My baby has arrived</label>
                <label class="check"><input type="radio" name="outcome" value="ended"> The pregnancy has ended</label>
            </div>
            <label class="narrow">Date <input type="date" name="ended_on" required max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
            <span class="inline-actions"><button class="btn" type="submit">Close this pregnancy record</button></span>
            <p class="muted small">Everything you've recorded is kept. To track bleeding, symptoms and a follow-up test after a loss, privately, use
                <a href="<?= h(url('pathway.php')) ?>">Pregnancy options and aftercare</a>.</p>
        </form>
    </details>
</section>
<?php endif; ?>
<p class="muted small">Pillbox sits alongside your maternity notes (paper or app), not instead of them. Based on NHS guidance on antenatal appointments, checks and vaccines.</p>
<?php endif; ?>

<?php if ($past): ?>
<section class="card">
    <h2>Earlier pregnancies</h2>
    <ul class="plain"><?php foreach ($past as $p): ?>
        <li class="logline"><span><?= $p['status'] === 'born' ? 'Baby born' : 'Ended' ?> <?= $p['ended_on'] ? h(date('j F Y', strtotime($p['ended_on']))) : '' ?>
            <span class="muted small">(due <?= h(date('j F Y', strtotime($p['due_date']))) ?>)</span></span></li>
    <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php render_footer();
