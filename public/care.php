<?php
/*
 * Care. care.php is an overview of the user's Care categories; care.php?area=<key>
 * is one category: its checks and renewals (due dates), routine (tasks ticked off on
 * Today), and extras: the sleep log, cycle tracking, screening from the health profile.
 * All care tick boxes and "done" buttons post here.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
$enabled = user_care_areas($user);
if (get('area') === 'mind') {
    require_mind($user);
}
$areaKey = isset(CARE_AREAS[get('area')]) ? get('area') : null;
$area = $areaKey ? CARE_AREAS[$areaKey] : null;
if ($areaKey && !share_area_allowed($areaKey)) {
    flash('error', "That part of {$user['display_name']}'s Pillbox hasn't been shared with you.");
    redirect('care.php');
}
if ($areaKey && isset($area['page'])) {
    redirect($area['page']); // categories with a page of their own (Pregnancy)
}
$self = $areaKey ? 'care.php?area=' . $areaKey : 'care.php';
$errors = [];
$form = null;        // routine task form with errors
$checkForm = null;   // check form with errors
$sleepErrors = $periodErrors = [];

if (is_post()) {
    require_post();
    $task = post('id') !== '' ? find_care_task((int) post('id'), $uid) : null;
    $check = post('check_id') !== '' ? find_health_check((int) post('check_id'), $uid) : null;
    $back = safe_return(post('return'), $self);
    if ($areaKey === 'menopause' && str_starts_with(post('action'), 'meno_')) {
        menopause_handle_post($user, $today);
    }
    if ($areaKey === 'bp' && str_starts_with(post('action'), 'bp_')) {
        bp_handle_post($user, $today);
    }
    if ($areaKey === 'allergies' && str_starts_with(post('action'), 'al_')) {
        allergies_handle_post($user, $today);
    }
    if ($areaKey === 'migraine' && str_starts_with(post('action'), 'mg_')) {
        migraine_handle_post($user, $today);
    }
    if ($areaKey === 'diabetes' && str_starts_with(post('action'), 'dm_')) {
        diabetes_handle_post($user, $today);
    }
    if ($areaKey === 'thyroid' && str_starts_with(post('action'), 'thy_')) {
        thyroid_handle_post($user, $today);
    }
    if ($areaKey === 'neuro' && str_starts_with(post('action'), 'nd_')) {
        nd_handle_post($user, $today);
    }
    if ($areaKey === 'womens' && str_starts_with(post('action'), 'pmdd_')) {
        pmdd_handle_post($user, $today);
    }
    if ($areaKey === 'bloods' && str_starts_with(post('action'), 'lab_')) {
        labs_handle_post($user, $today);
    }
    switch (post('action')) {
        /* ----- routine ----- */
        case 'toggle':
            $day = parse_date(post('date'));
            $slot = (int) post('slot');
            if (!$task || !$day || $day > $today || $day < $today->modify('-6 days') || !care_due($task, $day) || !isset(care_times($task)[$slot])) {
                not_found();
            }
            if (post('done') === '1') {
                q('INSERT IGNORE INTO care_logs (user_id, task_id, log_date, slot, done_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$uid, $task['id'], $day->format('Y-m-d'), $slot]);
            } else {
                q('DELETE FROM care_logs WHERE task_id = ? AND log_date = ? AND slot = ?', [$task['id'], $day->format('Y-m-d'), $slot]);
            }
            redirect($back);
        case 'add_preset':
            if ($areaKey && ($p = care_preset($areaKey, post('kind')))) {
                q('INSERT INTO care_tasks (user_id, area, kind, label, times, days_mask, every_days, starts_on, amount, tip, remind) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $areaKey, post('kind'), $p['label'], implode(',', $p['times']), $p['days'], $p['every'], $today->format('Y-m-d'), $p['amount'], $p['tip'], (int) $p['remind']]);
                flash('success', $p['label'] . ' added, '
                    . ($p['every'] ? care_every_label($p['every']) . ' from today' : mb_strtolower(days_label($p['days'])) . ' at ' . implode(' and ', $p['times']))
                    . ($p['remind'] ? ', with a calendar reminder' : '') . '. Use Change to adjust it.');
            }
            redirect($self . '#routine');
        case 'add':
        case 'update':
            if (!$areaKey || (post('action') === 'update' && (!$task || $task['area'] !== $areaKey))) {
                not_found();
            }
            [$values, $errors] = care_task_from_post($areaKey);
            if ($task && !$values['every_days']) {
                $values['starts_on'] = min($task['starts_on'], $values['starts_on']); // keep past days counting
            }
            if (!$errors) {
                $cols = [$values['label'], $values['times'], $values['days_mask'], $values['every_days'], $values['starts_on'], $values['amount'], $values['tip'], $values['remind']];
                if ($task) {
                    q('UPDATE care_tasks SET label = ?, times = ?, days_mask = ?, every_days = ?, starts_on = ?, amount = ?, tip = ?, remind = ? WHERE id = ?', [...$cols, $task['id']]);
                } else {
                    q('INSERT INTO care_tasks (label, times, days_mask, every_days, starts_on, amount, tip, remind, user_id, area, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [...$cols, $uid, $areaKey, $values['kind']]);
                }
                flash('success', $values['label'] . ($task ? ' updated.' : ' added.'));
                redirect($self . '#routine');
            }
            $form = $values + ['id' => $task['id'] ?? null];
            break;
        case 'delete':
            if ($task) {
                q('DELETE FROM care_tasks WHERE id = ?', [$task['id']]);
                flash('success', $task['label'] . ' removed from your routine.');
            }
            redirect($self . '#routine');

        /* ----- checks and renewals ----- */
        case 'check_preset':
            if ($areaKey && ($p = add_preset_check($uid, $areaKey, post('kind')))) {
                flash('success', $p['label'] . ' added. ' . ($p['rule'] ? 'Mark it done when you have it.' : 'Add when you last had it, or set the date it is due.'));
            }
            redirect($self . '#checks');
        case 'check_add':
        case 'check_update':
            if (!$areaKey || (post('action') === 'check_update' && (!$check || $check['area'] !== $areaKey))) {
                not_found();
            }
            [$values, $errors] = check_from_post($areaKey, $check);
            if (!$errors) {
                $cols = [$values['label'], $values['interval_days'], $values['last_done'], $values['due_date'], $values['quantity'], $values['unit'],
                    $values['low_at'], $values['per_week'], $values['counted_on'], $values['items'], $values['notes'], $values['remind'], $values['medication_id']];
                if ($check) {
                    q('UPDATE health_checks SET label = ?, interval_days = ?, last_done = ?, due_date = ?, quantity = ?, unit = ?, low_at = ?, per_week = ?,
                       counted_on = ?, items = ?, notes = ?, remind = ?, medication_id = ? WHERE id = ?', [...$cols, $check['id']]);
                } else {
                    q('INSERT INTO health_checks (label, interval_days, last_done, due_date, quantity, unit, low_at, per_week, counted_on, items, notes, remind, medication_id, user_id, area, track)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$cols, $uid, $areaKey, $values['track']]);
                }
                flash('success', $values['label'] . ($check ? ' updated.' : ' added.'));
                redirect($self . '#checks');
            }
            $checkForm = $values + ['id' => $check['id'] ?? null];
            break;
        case 'check_done':
            $day = parse_date(post('date'));
            if (!$check || !$day || $day > $today) {
                not_found();
            }
            q('UPDATE health_checks SET last_done = ?, due_date = NULL WHERE id = ?', [$day->format('Y-m-d'), $check['id']]);
            $check = ['last_done' => $day->format('Y-m-d'), 'due_date' => null] + $check;
            [$next] = check_next_due($check, $user, $today);
            flash('success', $check['track'] === 'expiry'
                ? preg_replace('/\s*\(once opened\)$/', '', $check['label']) . ': opened ' . $day->format('j M') . ($next ? '. Throw away after ' . $next->format('j F Y') . '.' : '.')
                : $check['label'] . ' marked done' . ($next ? '. Next due ' . $next->format('j F Y') . '.' : '.'));
            redirect($back);
        case 'check_replaced':
            $expiry = parse_date(post('expiry'));
            if (!$check || !$expiry) {
                flash('error', 'Enter the expiry date of the new one.');
                redirect($self . '#check-' . ($check['id'] ?? ''));
            }
            q('UPDATE health_checks SET last_done = ?, due_date = ? WHERE id = ?', [$today->format('Y-m-d'), $expiry->format('Y-m-d'), $check['id']]);
            flash('success', $check['label'] . ': replaced. The new one expires ' . $expiry->format('j F Y') . '.');
            redirect($back);
        case 'check_count':
            $n = post('quantity');
            if (!$check || !is_numeric($n) || (float) $n < 0 || (float) $n > 99999) {
                flash('error', 'Enter how many as a number.');
                redirect($self . '#check-' . ($check['id'] ?? ''));
            }
            $now = post('mode') === 'add' ? stock_left($check, $today) + (float) $n : (float) $n;
            q('UPDATE health_checks SET quantity = ?, counted_on = ?, last_done = ? WHERE id = ?',
                [$now, $today->format('Y-m-d'), post('mode') === 'add' ? $today->format('Y-m-d') : $check['last_done'], $check['id']]);
            flash('success', $check['label'] . ': ' . num(round($now)) . ($check['unit'] ? ' ' . $check['unit'] : '') . ' now.');
            redirect($back);
        case 'check_level':
            if ($check) {
                $collected = post('collected') === '1';
                $level = $collected ? 0 : (isset(FILL_LEVELS[(int) post('quantity')]) ? (int) post('quantity') : 0);
                q('UPDATE health_checks SET quantity = ?, counted_on = ?, last_done = ? WHERE id = ?',
                    [$level, $today->format('Y-m-d'), $collected ? $today->format('Y-m-d') : $check['last_done'], $check['id']]);
                flash('success', $collected ? $check['label'] . ': collected or replaced.' : $check['label'] . ': ' . mb_strtolower(FILL_LEVELS[$level]) . '.');
            }
            redirect($back);
        case 'check_certificate':
            $start = parse_date(post('start'));
            $months = (int) post('months');
            if (!$check || !$start || !in_array($months, check_lengths($check), true)) {
                flash('error', 'Enter the start date and how long the certificate lasts.');
                redirect($self . '#check-' . ($check['id'] ?? ''));
            }
            $end = $start->modify("+$months months")->modify('-1 day');
            q('UPDATE health_checks SET last_done = ?, due_date = ?, unit = ? WHERE id = ?', [$start->format('Y-m-d'), $end->format('Y-m-d'), "$months-month", $check['id']]);
            flash('success', $check['label'] . ': valid until ' . $end->format('j F Y') . '. You will be reminded a month before.');
            redirect($back);
        case 'result_add':
            $day = parse_date(post('taken_on'));
            $result = trim(mb_substr(post('result'), 0, 200));
            if (!$check || !$day || $day > $today || $result === '') {
                flash('error', 'Enter the date and the result.');
                redirect($self . '#check-' . ($check['id'] ?? ''));
            }
            q('INSERT INTO check_results (check_id, taken_on, result, notes) VALUES (?, ?, ?, ?)', [$check['id'], $day->format('Y-m-d'), $result, trim(mb_substr(post('notes'), 0, 500)) ?: null]);
            if (!$check['last_done'] || $check['last_done'] < $day->format('Y-m-d')) {
                q('UPDATE health_checks SET last_done = ?, due_date = NULL WHERE id = ?', [$day->format('Y-m-d'), $check['id']]);
            }
            flash('success', 'Result saved.');
            redirect($self . '#check-' . $check['id']);
        case 'result_delete':
            if ($check) {
                q('DELETE FROM check_results WHERE id = ? AND check_id = ?', [(int) post('result_id'), $check['id']]);
            }
            redirect($self . '#check-' . ($check['id'] ?? ''));
        case 'payment_add':
            $day = parse_date(post('paid_on'));
            $amount = post('amount');
            if (!$day || $day > $today || !is_numeric($amount) || (float) $amount < 0 || (int) post('items') < 0) {
                flash('error', 'Enter the date and the amount paid.');
                redirect($self . '#costs');
            }
            q('INSERT INTO prescription_payments (user_id, paid_on, items, amount, note, kind) VALUES (?, ?, ?, ?, ?, ?)',
                [$uid, $day->format('Y-m-d'), max(0, (int) post('items')), round((float) $amount, 2), trim(mb_substr(post('note'), 0, 200)) ?: null,
                    isset(SUPPLY_TYPES[post('kind')]) ? post('kind') : 'nhs']);
            redirect($self . '#costs');
        case 'payment_delete':
            q('DELETE FROM prescription_payments WHERE id = ? AND user_id = ?', [(int) post('payment_id'), $uid]);
            redirect($self . '#costs');
        case 'med_returned':
            q('UPDATE medications SET stock_count = 0 WHERE id = ? AND user_id = ? AND is_active = 0', [(int) post('med_id'), $uid]);
            flash('success', 'Thanks: marked as returned.');
            redirect($self . '#returns');
        case 'check_delete':
            if ($check) {
                q('DELETE FROM health_checks WHERE id = ?', [$check['id']]);
                flash('success', $check['label'] . ' removed.');
            }
            redirect($self . '#checks');
        case 'check_hide':
        case 'check_show':
            if ($check) {
                q('UPDATE health_checks SET hidden = ? WHERE id = ?', [post('action') === 'check_hide' ? 1 : 0, $check['id']]);
            }
            redirect($self . '#checks');

        /* ----- sleep log ----- */
        case 'log_sleep':
            [$night, $sleepErrors] = sleep_log_from_post($today);
            if ($night) {
                q("INSERT INTO health_readings (user_id, metric, value1, context, notes, recorded_at) VALUES (?, 'sleep', ?, ?, ?, ?)",
                    [$uid, $night['hours'], $night['context'], $night['notes'], $night['recorded_at']]);
                health_enable_metrics($uid, ['sleep']);
                flash('success', 'Sleep logged: ' . format_hours($night['hours']) . '.');
                redirect(safe_return(post('return'), 'care.php?area=sleep'));
            }
            break;

        /* ----- cycle tracking ----- */
        case 'period_save':
            $start = parse_date(post('start_date'));
            $end = post('end_date') === '' ? null : parse_date(post('end_date'));
            if (!$start || $start > $today) {
                $periodErrors[] = 'Enter the day your period started.';
            }
            if (post('end_date') !== '' && (!$end || ($start && $end < $start) || $end > $today)) {
                $periodErrors[] = 'The end date must be between the start date and today.';
            }
            $flow = isset(CYCLE_FLOW[post('flow')]) ? post('flow') : null;
            if (!$periodErrors) {
                q('INSERT INTO cycle_periods (user_id, start_date, end_date, flow, notes) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE end_date = VALUES(end_date), flow = VALUES(flow), notes = VALUES(notes)',
                    [$uid, $start->format('Y-m-d'), $end?->format('Y-m-d'), $flow, trim(mb_substr(post('notes'), 0, 255)) ?: null]);
                flash('success', 'Period ' . ($end ? 'saved.' : 'started ' . $start->format('j M') . '. Add the end date when it finishes.'));
                redirect('care.php?area=womens#cycle');
            }
            break;
        case 'period_end':
            $p = q('SELECT * FROM cycle_periods WHERE id = ? AND user_id = ?', [(int) post('period_id'), $uid])->fetch();
            if ($p) {
                q('UPDATE cycle_periods SET end_date = ? WHERE id = ?', [max($p['start_date'], $today->format('Y-m-d')), $p['id']]);
                flash('success', 'Period ended today.');
            }
            redirect('care.php?area=womens#cycle');
        case 'period_delete':
            q('DELETE FROM cycle_periods WHERE id = ? AND user_id = ?', [(int) post('period_id'), $uid]);
            redirect('care.php?area=womens#cycle');

        case 'hide_prompt':
        case 'show_prompt':
            q('UPDATE users SET care_prompt_hidden = ? WHERE id = ?', [post('action') === 'hide_prompt' ? 1 : 0, $uid]);
            if (post('action') === 'hide_prompt') {
                flash('success', 'Hidden from Today. You can set up care routines any time from the Care tab.');
            }
            redirect($back);
    }
}

/* ================= Overview: care.php ================= */
if (!$areaKey) {
    $allTasks = care_tasks($uid);
    $logs = $allTasks ? care_logs($uid, $today->format('Y-m-d'), $today->format('Y-m-d')) : [];
    if (in_array('screening', $enabled, true)) {
        sync_screening($user);
    }
    $allChecks = health_checks($uid);
    $comingUp = checks_coming_up($user, $enabled, $today);
    render_header('Care', 'care');
    ?>
<div class="page-head">
    <div>
        <h1>Care <?= help_link('care', '', 'Care') ?></h1>
        <p class="muted">Routines to tick off each day, and checks, screening and vaccinations to keep on top of.</p>
    </div>
    <span class="inline-actions">
        <a class="btn btn-small btn-primary" href="<?= h(url('care_summary.php')) ?>">Summary</a>
        <a class="btn btn-small" href="<?= h(url('account.php#health-profile')) ?>">Choose categories</a>
    </span>
</div>

<?php if ($comingUp): ?>
<section class="card">
    <h2>Coming up</h2>
    <ul class="plain">
    <?php foreach ($comingUp as $c): $a = CARE_AREAS[$c['check']['area']]; ?>
        <li class="logline">
            <span><?= $a['icon'] ?> <a href="<?= h(url('care.php?area=' . $c['check']['area'] . '#check-' . $c['check']['id'])) ?>"><strong><?= h($c['check']['label']) ?></strong></a>
                <span class="muted small"><?= h(check_due_text($c['check'], $c['due'], $today)) ?></span></span>
            <?= check_badge($c['check'], $c['status']) ?>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!$enabled): ?>
<section class="card"><p>All Care categories are switched off. <a href="<?= h(url('account.php#health-profile')) ?>">Choose the ones that are relevant to you</a>.</p></section>
<?php endif; ?>

<div class="care-grid">
    <?php foreach ($enabled as $key): $a = CARE_AREAS[$key];
        $tasks = array_filter($allTasks, fn ($t) => $t['area'] === $key);
        [$done, $due] = care_day_progress($tasks, $logs, $today);
        $next = null;
        foreach (array_filter($allChecks, fn ($c) => $c['area'] === $key) as $c) {
            [$d] = check_next_due($c, $user, $today);
            if ($d && (!$next || $d < $next[1])) {
                $next = [$c, $d];
            }
        } ?>
    <a class="care-tile" href="<?= h(url('care.php?area=' . $key)) ?>">
        <span class="care-tile-title"><?= $a['icon'] ?> <?= h($a['tile'] ?? $a['title']) ?></span>
        <span class="muted small"><?= $tasks ? ($due ? "$done of $due done today" : 'Nothing to do today') : ($a['presets'] ? 'No routine yet' : '') ?></span>
        <?php if (!empty($a['private'])): ?><span class="small">Open</span>
        <?php elseif ($key === 'pregnancy'): $preg = active_pregnancy($uid); ?>
        <span class="small"><?= $preg ? h(gestation_label($preg, $today)) . ' · due ' . h(date('j M', strtotime($preg['due_date']))) : 'Add your due date' ?></span>
        <?php elseif ($next): ?><span class="small">Next: <?= h($next[0]['label']) ?>, <?= h(lcfirst(check_due_text($next[0], $next[1], $today))) ?></span>
        <?php elseif ($key === 'screening' && !$user['date_of_birth']): ?><span class="small">Add your date of birth to see when you're due</span>
        <?php elseif (!$tasks): ?><span class="small">Set up</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php if (count($enabled) < count(care_categories())): ?>
<p class="muted small">Also available: <?= h(implode(', ', array_map(fn ($k) => CARE_AREAS[$k]['title'], array_diff(array_keys(care_categories()), $enabled)))) ?>.
    <a href="<?= h(url('account.php#health-profile')) ?>">Switch them on in your health profile</a>.</p>
<?php endif; ?>
<?php
    render_footer();
    exit;
}

/* ================= One area: care.php?area=<key> ================= */
if ($areaKey === 'screening') {
    sync_screening($user);
}
$tasks = care_tasks($uid, $areaKey);
$checks = health_checks($uid, $areaKey, true);
$hiddenChecks = array_filter($checks, fn ($c) => $c['hidden']);
$checks = array_filter($checks, fn ($c) => !$c['hidden']);
$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = $today->modify("-$i days"); // newest first, so today is visible without scrolling on phones
}
$logs = care_logs($uid, end($days)->format('Y-m-d'), $today->format('Y-m-d'));
$streak = care_streak($uid, $tasks, $today);
$usedKinds = array_column($tasks, 'kind');
$usedCheckKinds = array_column(health_checks($uid, $areaKey, true), 'kind');
$next = $last = null;
if ($area['appointment']) {
    $next = q('SELECT * FROM appointments WHERE user_id = ? AND kind = ? AND starts_at >= UTC_TIMESTAMP() ORDER BY starts_at LIMIT 1', [$uid, $area['appointment']])->fetch();
    $last = $next ? null : q('SELECT * FROM appointments WHERE user_id = ? AND kind = ? AND starts_at < UTC_TIMESTAMP() ORDER BY starts_at DESC LIMIT 1', [$uid, $area['appointment']])->fetch();
    $apptLabel = mb_strtolower(explode(' /', appointment_type_label($area['appointment']))[0]);
}
$remindersOff = !$user['calendar_token'] && (array_filter($tasks, fn ($t) => $t['remind']) || array_filter($checks, fn ($c) => $c['remind']));
if ($areaKey === 'sleep') {
    $nights = sleep_nights($uid, $today->modify('-13 days'));
    $lastNight = $nights[0] ?? null;
    $bedtimeTask = current(array_filter($tasks, fn ($t) => $t['kind'] === 'bedtime')) ?: null;
    $sleepForm = ['night' => $today->modify('-1 day')->format('Y-m-d'), 'bed_time' => $bedtimeTask ? care_times($bedtimeTask)[0] : '23:00',
        'wake_time' => '07:00', 'awake_minutes' => '', 'quality' => '', 'notes' => ''];
    if ($sleepErrors) {
        foreach (array_keys($sleepForm) as $f) {
            $sleepForm[$f] = post($f);
        }
    }
}
if ($areaKey === 'womens') {
    $periods = cycle_periods($uid, 12);
    $cycle = cycle_stats($periods, $today->getTimezone());
    $ongoing = $periods && !$periods[0]['end_date'] && $periods[0]['start_date'] >= $today->modify('-10 days')->format('Y-m-d') ? $periods[0] : null;
}
$routineFirst = (bool) $area['presets'];

/** Fields shared by the routine task add and change forms. */
function care_fields(array $t, string $idPrefix): void
{
    $times = array_pad(care_times($t), CARE_MAX_TIMES, '');
    $interval = (bool) $t['every_days'];
    ?>
    <div class="grid-2">
        <label>Name <input name="label" maxlength="100" required value="<?= h($t['label']) ?>"></label>
        <label><span>Amount <span class="muted small">(optional: reps, sets or minutes)</span></span> <input name="amount" maxlength="60" value="<?= h($t['amount'] ?? '') ?>" placeholder="e.g. 3 sets of 10"></label>
    </div>
    <label><span>Tip or reminder <span class="muted small">(optional)</span></span> <input name="tip" maxlength="255" value="<?= h($t['tip'] ?? '') ?>"></label>
    <fieldset>
        <legend>Times of day <span class="muted small">(leave spare boxes empty)</span></legend>
        <div class="row-fields">
            <?php foreach ($times as $n => $time): ?>
            <label><span class="sr">Time <?= $n + 1 ?></span><input type="time" name="times[]" value="<?= h($time) ?>"<?= $n === 0 ? ' required' : '' ?>></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset>
        <legend>Repeat</legend>
        <div class="repeat-choice">
            <label class="check"><input type="radio" name="repeat" value="days" id="<?= h($idPrefix) ?>-days"<?= checked(!$interval) ?>> On these days</label>
            <label class="check"><input type="radio" name="repeat" value="interval" id="<?= h($idPrefix) ?>-interval"<?= checked($interval) ?>> Every few days or weeks</label>
        </div>
        <div class="days" role="group" aria-label="Days" data-show-when="<?= h($idPrefix) ?>-days">
            <?php foreach (WEEKDAYS as $bit => $label): ?>
            <label class="day"><input type="checkbox" name="days[]" value="<?= $bit ?>"<?= checked(((int) $t['days_mask'] & $bit) !== 0) ?>><span><?= $label ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="row-fields interval-fields" data-show-when="<?= h($idPrefix) ?>-interval">
            <label>Every <span class="inline-input"><input type="number" name="every_days" min="2" max="366" inputmode="numeric" value="<?= h((string) ($t['every_days'] ?: 14)) ?>"> days</span></label>
            <label>Counting from <input type="date" name="starts_on" value="<?= h($t['starts_on'] ?? local_today()->format('Y-m-d')) ?>"></label>
        </div>
    </fieldset>
    <label class="check"><input type="checkbox" name="remind" value="1"<?= checked(!empty($t['remind'])) ?>>
        <span>Remind me <span class="muted small">(adds it to your calendar feed with an alert, so your phone's calendar reminds you)</span></span></label>
    <?php
}

/** Status badge, worded for the kind of check. */
function check_badge(array $c, string $status): string
{
    $words = match ($c['track']) {
        'expiry' => ['overdue' => 'Expired', 'soon' => 'Expires soon', 'later' => 'In date'],
        'stock'  => ['overdue' => 'Restock', 'soon' => 'Restock soon', 'later' => 'Stocked'],
        'level'  => ['overdue' => 'Collect now', 'soon' => 'Collect soon', 'later' => 'OK'],
        'kit'    => ['overdue' => 'Needs attention', 'soon' => 'Check soon', 'later' => 'Up to date'],
        default  => ['overdue' => 'Overdue', 'soon' => 'Due soon', 'later' => 'Up to date'],
    };
    $class = ['overdue' => 'missed', 'soon' => 'due', 'later' => 'taken'][$status] ?? null;
    return $class ? '<span class="badge badge-' . $class . '">' . h($words[$status]) . '</span>' : '';
}

/** The quick action for a check: mark done, replaced (new expiry), count, or fill level. */
function check_actions(array $c, string $self, DateTimeImmutable $today): void
{
    $id = (int) $c['id'];
    ?>
    <form method="post" action="<?= h(url($self)) ?>" class="done-form">
        <?= csrf_field() ?><input type="hidden" name="check_id" value="<?= $id ?>">
        <?php if ($c['track'] === 'expiry' && ($lengths = check_lengths($c))): ?>
            <input type="hidden" name="action" value="check_certificate">
            <label><span class="small">New certificate starts</span><input type="date" name="start" required value="<?= h($c['due_date'] && $c['due_date'] >= $today->format('Y-m-d') ? (new DateTimeImmutable($c['due_date']))->modify('+1 day')->format('Y-m-d') : $today->format('Y-m-d')) ?>"></label>
            <select name="months" aria-label="How long"><?php foreach ($lengths as $mo): ?><option value="<?= $mo ?>"<?= selected($c['unit'] === "$mo-month") ?>><?= $mo ?> months</option><?php endforeach; ?></select>
            <button class="btn btn-small btn-primary" type="submit">Save</button>
        <?php elseif ($c['track'] === 'expiry' && $c['interval_days']): ?>
            <input type="hidden" name="action" value="check_done">
            <label><span class="small">Opened on</span><input type="date" name="date" required max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($today->format('Y-m-d')) ?>"></label>
            <button class="btn btn-small btn-primary" type="submit">Opened a new one</button>
        <?php elseif ($c['track'] === 'expiry'): ?>
            <input type="hidden" name="action" value="check_replaced">
            <label><span class="small">New one expires</span><input type="date" name="expiry" required min="<?= h($today->format('Y-m-d')) ?>"></label>
            <button class="btn btn-small btn-primary" type="submit">Replaced</button>
        <?php elseif ($c['track'] === 'stock'): ?>
            <input type="hidden" name="action" value="check_count">
            <label><span class="small">How many</span><input type="number" name="quantity" min="0" step="any" inputmode="decimal" required class="count-input"></label>
            <select name="mode" aria-label="Count or restock"><option value="set">I have this many</option><option value="add">Add to it (restocked)</option></select>
            <button class="btn btn-small btn-primary" type="submit">Update</button>
        <?php elseif ($c['track'] === 'level'): ?>
            <input type="hidden" name="action" value="check_level">
            <select name="quantity" aria-label="How full"><?php foreach (FILL_LEVELS as $v => $l): ?><option value="<?= $v ?>"<?= selected((int) $c['quantity'] === $v) ?>><?= h($l) ?></option><?php endforeach; ?></select>
            <button class="btn btn-small" type="submit">Update</button>
            <button class="btn btn-small btn-primary" type="submit" name="collected" value="1">Collected</button>
        <?php else: ?>
            <input type="hidden" name="action" value="check_done">
            <label class="sr" for="done-<?= $id ?>">Date</label>
            <input type="date" id="done-<?= $id ?>" name="date" required max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($today->format('Y-m-d')) ?>">
            <button class="btn btn-small btn-primary" type="submit"><?= $c['track'] === 'kit' ? 'Checked' : 'Mark done' ?></button>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * Fields for the check add and change forms. $prefix set = a new check, so the type can be chosen
 * (the other types' fields are hidden by app.js until picked).
 */
function check_fields(array $c, ?string $prefix = null): void
{
    [$n, $unit] = interval_parts($c['interval_days'] ? (int) $c['interval_days'] : null);
    $track = $c['track'] ?? 'due';
    $show = fn (array $tracks) => $prefix
        ? ' data-show-when="' . h(implode(' ', array_map(fn ($t) => "$prefix-$t", $tracks))) . '"'
        : (in_array($track, $tracks, true) ? '' : ' hidden');
    $today = local_today()->format('Y-m-d');
    ?>
    <label>Name <input name="label" maxlength="100" required value="<?= h($c['label']) ?>"></label>
    <?php if ($prefix): ?>
    <fieldset><legend>What kind?</legend>
        <div class="repeat-choice">
            <?php foreach (CHECK_TRACKS as $k => $l): ?>
            <label class="check"><input type="radio" name="track" value="<?= h($k) ?>" id="<?= h("$prefix-$k") ?>"<?= checked($track === $k) ?>> <?= h($l) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endif; ?>
    <div class="grid-2">
        <?php if (empty($c['rule'])): ?>
        <label<?= $show(['due', 'level', 'kit', 'expiry']) ?>><span><?= $track === 'expiry' && !$prefix ? 'Use within, once opened' : 'Repeats every' ?>
            <span class="muted small">(<?= $prefix ? 'or, for an expiry date: use within this long of opening; ' : '' ?>empty = <?= $track === 'expiry' ? 'a fixed expiry date' : 'one-off' ?>)</span></span>
            <span class="inline-input"><input type="number" name="interval_n" min="1" max="7300" inputmode="numeric" value="<?= h($n) ?>">
                <select name="interval_unit" aria-label="Unit">
                    <?php foreach (['days', 'weeks', 'months', 'years'] as $u): ?><option<?= selected($unit === $u) ?>><?= $u ?></option><?php endforeach; ?>
                </select></span></label>
        <?php endif; ?>
        <label<?= $show(['due', 'level', 'kit', 'expiry']) ?>><?= $track === 'kit' && !$prefix ? 'Last checked' : ($track === 'level' && !$prefix ? 'Last collected' : ($track === 'expiry' && !$prefix ? 'Last replaced' : 'Last done')) ?>
            <input type="date" name="last_done" max="<?= h($today) ?>" value="<?= h($c['last_done'] ?? '') ?>"></label>
        <label<?= $show(['due', 'expiry']) ?>><span><?= $track === 'expiry' && !$prefix ? 'Expiry date' : 'Next due, or expiry date' ?>
            <span class="muted small"><?= $track === 'expiry' ? '' : '(optional: overrides the worked-out date)' ?></span></span>
            <input type="date" name="due_date" value="<?= h($c['due_date'] ?? '') ?>"></label>
        <label<?= $show(['stock']) ?>>How many you have <input type="number" name="quantity" min="0" step="any" inputmode="decimal" value="<?= h($track === 'stock' && $c['quantity'] !== null ? num($c['quantity']) : '') ?>"></label>
        <label<?= $show(['stock']) ?>><span>Called <span class="muted small">(e.g. strips)</span></span> <input name="unit" maxlength="20" value="<?= h($c['unit'] ?? '') ?>"></label>
        <label<?= $show(['stock']) ?>>Restock when down to <input type="number" name="low_at" min="0" step="any" inputmode="decimal" value="<?= h(isset($c['low_at']) ? num($c['low_at']) : '') ?>"></label>
        <label<?= $show(['stock']) ?>><span>Roughly how many you use a week <span class="muted small">(optional)</span></span>
            <input type="number" name="per_week" min="0" step="any" inputmode="decimal" value="<?= h(!empty($c['per_week']) ? num($c['per_week']) : '') ?>"></label>
        <?php if (!$prefix && $track === 'level'): ?><input type="hidden" name="quantity" value="<?= (int) $c['quantity'] ?>"><?php endif; ?>
    </div>
    <?php if (($GLOBALS['areaKey'] ?? '') === 'bloods' || !empty($c['medication_id'])): $meds = user_medications((int) current_user()['id']); ?>
    <label class="narrow"><span>For which medicine <span class="muted small">(optional)</span></span>
        <select name="medication_id"><option value="">&ndash;</option><?php foreach ($meds as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected((int) ($c['medication_id'] ?? 0) === (int) $m['id']) ?>><?= h($m['name'] . ' ' . $m['strength']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label<?= $show(['kit']) ?>><span>Contents <span class="muted small">(one per line; add an expiry after a bar, e.g. "Paracetamol | 2027-03")</span></span>
        <textarea name="items" rows="6" maxlength="5000"><?= h($c['items'] ?? '') ?></textarea></label>
    <label><span>Notes <span class="muted small">(optional)</span></span> <input name="notes" maxlength="500" value="<?= h($c['notes'] ?? '') ?>"></label>
    <label class="check"><input type="checkbox" name="remind" value="1"<?= checked(!empty($c['remind'])) ?>>
        <span>Show in my calendar feed when it's due</span></label>
    <?php
}

render_header($area['title'], $areaKey === 'mind' ? 'mind' : 'care');
?>
<div class="page-head">
    <div>
        <h1><?= $area['icon'] ?> <?= h($area['title']) ?> <?= help_link(CARE_DOC_PAGES[$areaKey] ?? 'care-categories', isset(CARE_DOC_PAGES[$areaKey]) ? '' : docs_slug($area['title']), $area['title']) ?></h1>
        <?php if ($tasks): ?><p class="muted"><?= $streak && !$user['hide_streaks'] ? "$streak day" . ($streak === 1 ? '' : 's') . ' in a row with your whole routine done' : 'Tick tasks off on the Today page' ?></p><?php endif; ?>
    </div>
    <span class="inline-actions">
        <?php if ($areaKey === 'mind'): ?>
        <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
        <?php else: ?>
        <a class="btn btn-small" href="<?= h(url('care_summary.php?area=' . $areaKey)) ?>">Summary</a>
        <a class="btn btn-small" href="<?= h(url('care.php')) ?>">&larr; Care</a>
        <?php endif; ?>
    </span>
</div>

<?php if ($areaKey !== 'mind'): ?>
<nav class="chips" aria-label="Care categories">
    <?php foreach (array_unique([...$enabled, $areaKey]) as $key): $a = CARE_AREAS[$key]; ?>
    <a class="chip" href="<?= h(url('care.php?area=' . $key)) ?>"<?= $key === $areaKey ? ' aria-current="page"' : '' ?>><?= $a['icon'] ?> <?= h($a['short']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (!in_array($areaKey, routine_areas($user), true)): ?>
<p class="flash flash-warning">This category is switched off, so it isn't on your Today page or calendar feed.
    <a href="<?= h(url('account.php#health-profile')) ?>">Switch it on in your health profile</a>.</p>
<?php endif; ?>

<?php if ($areaKey === 'sleep'):
    $avg = $nights ? array_sum(array_map(fn ($n) => (float) $n['value1'], array_slice($nights, 0, 7))) / min(7, count($nights)) : null; ?>
<section class="card" id="log">
    <div class="card-head">
        <h2>Sleep log</h2>
        <a class="btn btn-small" href="<?= h(url('readings.php?metric=sleep')) ?>">All nights</a>
    </div>
    <?php if ($nights): ?>
    <p class="muted small">Last night: <strong><?= h(format_hours((float) $lastNight['value1'])) ?></strong><?= $lastNight['context'] ? ' · ' . h($lastNight['context']) : '' ?>
        <?= $lastNight['source'] ? ' · from ' . h(reading_source_label($lastNight['source'])) : '' ?>
        &middot; average <?= h(format_hours($avg)) ?> over your last <?= min(7, count($nights)) ?> night<?= min(7, count($nights)) === 1 ? '' : 's' ?></p>
    <?php $bars = array_reverse(array_slice($nights, 0, 14)); $w = 100 / 14; ?>
    <svg class="sleep-bars" viewBox="0 0 100 34" preserveAspectRatio="none" role="img"
        aria-label="<?= h('Sleep, last ' . count($bars) . ' nights: ' . implode(', ', array_map(fn ($n) => local_time($n['recorded_at'], 'D j') . ' ' . format_hours((float) $n['value1']), $bars))) ?>">
        <line class="sleep-target" x1="0" x2="100" y1="<?= 30 - 30 * 7 / 10 ?>" y2="<?= 30 - 30 * 7 / 10 ?>"/>
        <?php foreach ($bars as $i => $n): $bh = 30 * min(10, (float) $n['value1']) / 10; ?>
        <rect x="<?= round($i * $w + $w * .15, 2) ?>" y="<?= round(30 - $bh, 2) ?>" width="<?= round($w * .7, 2) ?>" height="<?= round($bh, 2) ?>" rx=".6"><title><?= h(local_time($n['recorded_at'], 'D j M') . ': ' . format_hours((float) $n['value1'])) ?></title></rect>
        <?php endforeach; ?>
    </svg>
    <p class="muted small">Up to 14 nights, oldest on the left. The line marks 7 hours.</p>
    <?php endif; ?>
    <details class="q-edit"<?= $sleepErrors || !$nights || local_time($lastNight['recorded_at'], 'Y-m-d') !== $today->format('Y-m-d') ? ' open' : '' ?>>
        <summary>Log a night</summary>
        <?= form_errors($sleepErrors) ?>
        <form method="post" action="<?= h(url($self)) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="log_sleep">
            <div class="grid-2">
                <label>Night of <input type="date" name="night" required max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($sleepForm['night']) ?>"></label>
                <label>How did you sleep?
                    <select name="quality">
                        <option value="">Choose&hellip;</option>
                        <?php foreach (SLEEP_QUALITY as $q): ?><option<?= selected($sleepForm['quality'] === $q) ?>><?= h($q) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Went to bed <input type="time" name="bed_time" required value="<?= h($sleepForm['bed_time']) ?>"></label>
                <label>Got up <input type="time" name="wake_time" required value="<?= h($sleepForm['wake_time']) ?>"></label>
                <label><span>Awake in the night <span class="muted small">(minutes, roughly)</span></span> <input type="number" name="awake_minutes" min="0" max="900" inputmode="numeric" value="<?= h($sleepForm['awake_minutes']) ?>"></label>
                <label><span>Notes <span class="muted small">(optional)</span></span> <input name="notes" maxlength="400" value="<?= h($sleepForm['notes']) ?>" placeholder="e.g. CPAP mask leaked, late coffee"></label>
            </div>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save night</button></span>
        </form>
    </details>
    <p class="muted small">Nights from Apple Health appear here too. Sleep is saved as a reading, so it's in your History and reports.</p>
</section>
<?php endif; ?>

<?php if ($areaKey === 'menopause') { menopause_sections($user, $today); } ?>
<?php if ($areaKey === 'bloods') { labs_sections($user, $today); } ?>
<?php if ($areaKey === 'womens') { pmdd_sections($user, $today); } ?>
<?php if ($areaKey === 'neuro') { nd_sections($user, $today); } ?>
<?php if ($areaKey === 'thyroid') { thyroid_sections($user, $today); } ?>
<?php if ($areaKey === 'diabetes') { diabetes_sections($user, $today); } ?>
<?php if ($areaKey === 'migraine') { migraine_sections($user, $today); } ?>
<?php if ($areaKey === 'allergies') { allergies_sections($user, $today); } ?>
<?php if ($areaKey === 'bp') { bp_sections($user, $today); } ?>

<?php if ($areaKey === 'womens'): ?>
<section class="card" id="cycle">
    <h2>Cycle tracking</h2>
    <?php if ($ongoing): ?>
        <p>Period started <strong><?= h((new DateTimeImmutable($ongoing['start_date']))->format('l j F')) ?></strong>
            (day <?= (new DateTimeImmutable($ongoing['start_date']))->diff($today)->days + 1 ?>).</p>
        <form method="post" action="<?= h(url($self)) ?>" class="inline-actions">
            <?= csrf_field() ?><input type="hidden" name="action" value="period_end"><input type="hidden" name="period_id" value="<?= (int) $ongoing['id'] ?>">
            <button class="btn btn-primary" type="submit">It ended today</button>
        </form>
    <?php elseif ($cycle['next']):
        $daysTo = (int) $today->diff($cycle['next'])->format('%r%a'); ?>
        <p class="cycle-next">Next period expected around <strong><?= h($cycle['next']->format('l j F')) ?></strong>
            <span class="muted">(<?= $daysTo > 1 ? "in $daysTo days" : ($daysTo === 1 ? 'tomorrow' : ($daysTo === 0 ? 'today' : -$daysTo . ' days ago')) ?>)</span></p>
    <?php elseif ($periods): ?>
        <p class="muted">Log at least two periods to see when the next one is expected.</p>
    <?php endif; ?>
    <?php if ($cycle['cycle']): ?>
    <div class="tiles">
        <div class="tile"><span class="tile-label">Average cycle</span><span class="tile-value"><?= (int) round($cycle['cycle']) ?> days</span>
            <span class="tile-meta"><?= $cycle['cycles'] > 1 ? 'From ' . $cycle['shortest'] . ' to ' . $cycle['longest'] . ' days' : 'From 1 cycle' ?></span></div>
        <?php if ($cycle['period']): ?>
        <div class="tile"><span class="tile-label">Average period</span><span class="tile-value"><?= round($cycle['period'], 1) ?> days</span><span class="tile-meta">Last <?= min(6, count($periods)) ?> periods</span></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <details class="q-edit"<?= $periodErrors || !$periods ? ' open' : '' ?>>
        <summary>Log a period</summary>
        <?= form_errors($periodErrors) ?>
        <form method="post" action="<?= h(url($self)) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="period_save">
            <div class="grid-2">
                <label>Started <input type="date" name="start_date" required max="<?= h($today->format('Y-m-d')) ?>" value="<?= h(post('start_date') ?: $today->format('Y-m-d')) ?>"></label>
                <label><span>Ended <span class="muted small">(leave empty if it hasn't)</span></span> <input type="date" name="end_date" max="<?= h($today->format('Y-m-d')) ?>" value="<?= h(post('end_date')) ?>"></label>
                <label>Flow
                    <select name="flow"><option value="">Not recorded</option>
                        <?php foreach (CYCLE_FLOW as $k => $l): ?><option value="<?= h($k) ?>"<?= selected(post('flow') === $k) ?>><?= h($l) ?></option><?php endforeach; ?>
                    </select></label>
                <label><span>Notes <span class="muted small">(optional)</span></span> <input name="notes" maxlength="255" value="<?= h(post('notes')) ?>" placeholder="e.g. cramps, spotting"></label>
            </div>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
        </form>
    </details>
    <?php if ($periods): ?>
    <h3>Recent periods</h3>
    <ul class="plain">
        <?php foreach ($periods as $i => $p): $len = isset($periods[$i + 1]) ? (new DateTimeImmutable($periods[$i + 1]['start_date']))->diff(new DateTimeImmutable($p['start_date']))->days : null; ?>
        <li class="logline">
            <span><strong><?= h((new DateTimeImmutable($p['start_date']))->format('j M Y')) ?></strong>
                <span class="muted small"><?= $p['end_date'] ? (new DateTimeImmutable($p['start_date']))->diff(new DateTimeImmutable($p['end_date']))->days + 1 . ' days' : 'no end date' ?>
                    <?= $p['flow'] ? ' · ' . h(CYCLE_FLOW[$p['flow']]) : '' ?><?= $len ? " · cycle $len days" : '' ?><?= $p['notes'] ? ' · ' . h($p['notes']) : '' ?></span></span>
            <form method="post" action="<?= h(url($self)) ?>" data-confirm="Delete this period?">
                <?= csrf_field() ?><input type="hidden" name="action" value="period_delete"><input type="hidden" name="period_id" value="<?= (int) $p['id'] ?>">
                <button class="btn-link danger" type="submit">Delete</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="muted small">Predictions are an average of your last 6 cycles. They can't tell you when it's safe to have sex without contraception.</p>
</section>
<?php endif; ?>

<?php if ($areaKey === 'respiratory'):
    $inhalers = array_filter(user_medications($uid), fn ($m) => $m['form'] === 'inhaler' || $m['dose_unit'] === 'puff');
    $inhSchedules = schedules_by_medication(array_column($inhalers, 'id'), $today->format('Y-m-d')); ?>
<section class="card" id="inhalers">
    <div class="card-head"><h2>My inhalers</h2><a class="btn btn-small" href="<?= h(url('medications.php')) ?>">Meds</a></div>
    <?php if (!$inhalers): ?>
    <p class="muted">Add your reliever and preventer as medications (form "inhaler", dose unit "puff"). Put the number on the dose counter in
        "Stock on hand" and the expiry date from the box: Pillbox counts down as you log puffs, and warns you before either runs out.</p>
    <?php endif; ?>
    <ul class="plain">
        <?php foreach ($inhalers as $m): $days = stock_days_left($m, $inhSchedules[$m['id']] ?? []); [$expState, $expText] = expiry_status($m['expiry_date'], $today); ?>
        <li class="logline"><span><?= med_dot($m['colour']) ?> <strong><?= h($m['name']) ?></strong> <span class="muted small"><?= h($m['strength']) ?><?= $m['as_needed'] ? ' · reliever (as needed)' : ' · preventer' ?></span>
            <br><span class="small<?= $days !== null && $days <= (int) $m['low_stock_days'] ? ' warn' : '' ?>"><?= $m['stock_count'] !== null
                ? h(num($m['stock_count'])) . ' doses left on the counter' . ($days !== null ? ' (about ' . (int) floor($days) . ' days)' : '') : 'Dose counter not recorded' ?></span>
            <?= $expText ? '<span class="small' . ($expState ? ' warn' : ' muted') . '"> · ' . h(lcfirst($expText)) . '</span>' : '<span class="muted small"> · no expiry date</span>' ?></span>
            <a class="btn btn-small" href="<?= h(url('medication_edit.php?id=' . $m['id'])) ?>">Update</a></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($areaKey === 'sharps'):
    $toReturn = q('SELECT * FROM medications WHERE user_id = ? AND ((is_active = 0 AND stock_count > 0) OR (is_active = 1 AND expiry_date < ?)) ORDER BY name',
        [$uid, $today->format('Y-m-d')])->fetchAll(); ?>
<section class="card" id="returns">
    <h2>Medicines to take back to a pharmacy</h2>
    <?php if (!$toReturn): ?><p class="muted">Nothing at the moment: Pillbox lists stopped medicines you still have some of, and anything past its expiry date.</p><?php endif; ?>
    <ul class="plain">
        <?php foreach ($toReturn as $m): ?>
        <li class="logline"><span><strong><?= h($m['name']) ?></strong> <span class="muted small"><?= h($m['strength']) ?> ·
            <?= $m['is_active'] ? 'expired ' . h(date('j M Y', strtotime($m['expiry_date']))) : 'stopped, ' . h(dose_label($m['stock_count'], $m['dose_unit'])) . ' left' ?></span></span>
            <?php if (!$m['is_active']): ?>
            <form method="post" action="<?= h(url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="med_returned"><input type="hidden" name="med_id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn-small" type="submit">Returned</button></form>
            <?php else: ?><a class="btn btn-small" href="<?= h(url('medications.php#med-' . $m['id'])) ?>">New supply</a><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($areaKey === 'prescriptions'):
    $price = fn (string $k, float $d) => (float) config("prescriptions.$k", $d);
    $charge = $price('charge', 9.90);
    $ppc3 = $price('ppc_3', 32.05);
    $ppc12 = $price('ppc_12', 114.50);
    $payments = q('SELECT * FROM prescription_payments WHERE user_id = ? ORDER BY paid_on DESC, id DESC', [$uid])->fetchAll();
    $since = fn (int $months) => array_filter($payments, fn ($p) => $p['paid_on'] >= $today->modify("-$months months")->format('Y-m-d'));
    $sum = fn (array $rows, string $f) => array_sum(array_map(fn ($r) => (float) $r[$f], $rows));
    $last3 = $since(3);
    $last12 = $since(12);
    // Only NHS charges count towards a PPC; private and bought medicines are shown apart.
    $nhsOnly = fn (array $rows) => array_filter($rows, fn ($p) => $p['kind'] === 'nhs');
    $myMeds = user_medications($uid);
    $regular = count(array_filter($myMeds, fn ($m) => !$m['as_needed'] && $m['supply_type'] === 'nhs'));
    $notNhs = array_filter($myMeds, fn ($m) => $m['supply_type'] !== 'nhs');
    $otherPaid = fn (array $rows) => $sum(array_filter($rows, fn ($p) => $p['kind'] !== 'nhs'), 'amount');
    $age = $user['date_of_birth'] ? (new DateTimeImmutable($user['date_of_birth']))->diff($today)->y : null;
    $activePpc = q("SELECT * FROM health_checks WHERE user_id = ? AND area = 'prescriptions' AND kind IN ('ppc', 'medex', 'matex', 'hc2') AND hidden = 0 AND due_date >= ? ORDER BY due_date DESC LIMIT 1",
        [$uid, $today->format('Y-m-d')])->fetch(); ?>
<section class="card" id="costs">
    <h2>What prescriptions cost you</h2>
    <?php if ($age !== null && $age >= 60): ?>
    <p class="flash flash-success">You're <?= $age ?>, so NHS prescriptions are free in England. You shouldn't need to pay or buy a PPC.</p>
    <?php elseif ($activePpc): ?>
    <p class="flash flash-success">Your <?= h($activePpc['label']) ?> covers you until <?= h(date('j F Y', strtotime($activePpc['due_date']))) ?>, so there's nothing to pay for NHS prescriptions until then.</p>
    <?php endif; ?>
    <div class="tiles">
        <div class="tile"><span class="tile-label">Last 3 months</span><span class="tile-value">&pound;<?= number_format($sum($last3, 'amount'), 2) ?></span><span class="tile-meta"><?= (int) $sum($last3, 'items') ?> items<?= $otherPaid($last3) > 0 ? ' · £' . number_format($otherPaid($last3), 2) . ' not NHS' : '' ?></span></div>
        <div class="tile"><span class="tile-label">Last 12 months</span><span class="tile-value">&pound;<?= number_format($sum($last12, 'amount'), 2) ?></span><span class="tile-meta"><?= (int) $sum($last12, 'items') ?> items<?= $otherPaid($last12) > 0 ? ' · £' . number_format($otherPaid($last12), 2) . ' not NHS' : '' ?></span></div>
    </div>
    <h3>Would a prepayment certificate save money?</h3>
    <ul class="insights">
        <li>A 3-month PPC (&pound;<?= number_format($ppc3, 2) ?>) pays for itself at <?= (int) ceil($ppc3 / $charge) ?> items in 3 months; a 12-month PPC (&pound;<?= number_format($ppc12, 2) ?>)
            at <?= (int) ceil($ppc12 / $charge) ?> items in a year (&pound;<?= number_format($charge, 2) ?> an item).</li>
        <?php if ($nhsOnly($last12)): $paid = $sum($nhsOnly($last12), 'amount'); ?>
        <li>In the last 12 months you paid NHS charges of &pound;<?= number_format($paid, 2) ?>: <?= $paid > $ppc12 ? '<strong>a 12-month PPC would have saved &pound;' . number_format($paid - $ppc12, 2) . '</strong>' : 'a 12-month PPC would have cost &pound;' . number_format($ppc12 - $paid, 2) . ' more' ?>.</li>
        <?php endif; ?>
        <?php if ($nhsOnly($last3)): $paid3 = $sum($nhsOnly($last3), 'amount'); ?>
        <li>In the last 3 months you paid NHS charges of &pound;<?= number_format($paid3, 2) ?>: <?= $paid3 > $ppc3 ? '<strong>a 3-month PPC would have saved &pound;' . number_format($paid3 - $ppc3, 2) . '</strong>' : 'a 3-month PPC would have cost &pound;' . number_format($ppc3 - $paid3, 2) . ' more' ?>.</li>
        <?php endif; ?>
        <?php if ($regular): $yearly = $regular * 13 * $charge; ?>
        <li>With <?= $regular ?> regular NHS-prescribed medicine<?= $regular === 1 ? '' : 's' ?> (about <?= $regular * 13 ?> items a year, for 28-day supplies) you'd pay about &pound;<?= number_format($yearly, 2) ?> a year.
            <?= $yearly > $ppc12 ? '<strong>A 12-month PPC would save about &pound;' . number_format($yearly - $ppc12, 2) . '.</strong>' : 'Paying per item is cheaper.' ?></li>
        <?php endif; ?>
        <?php if ($notNhs): ?>
        <li>Not covered by a PPC or exemption: <?= h(implode(', ', array_map(fn ($m) => $m['name'] . ' (' . ($m['supply_type'] === 'private' ? 'private prescription' : 'bought')
            . ($m['supply_price'] !== null ? ', £' . number_format((float) $m['supply_price'], 2) : '') . ')', $notNhs))) ?>.
            Their payments are logged here but left out of the sums above.</li>
        <?php endif; ?>
    </ul>
    <p class="muted small">Prices from <?= h((string) config('prescriptions.prices_from', 'May 2025')) ?> (England). They usually change each April: check nhs.uk for the current ones.</p>
    <details class="q-edit"<?= $payments ? '' : ' open' ?>><summary>Log a payment</summary>
        <form method="post" action="<?= h(url($self)) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="payment_add">
            <label>Date <input type="date" name="paid_on" required max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($today->format('Y-m-d')) ?>"></label>
            <label>Items <input type="number" name="items" min="0" max="50" value="1" class="count-input" data-charge="<?= h((string) $charge) ?>"></label>
            <label>Paid (&pound;) <input type="number" name="amount" min="0" step="0.01" required value="<?= h(number_format($charge, 2, '.', '')) ?>" class="count-input"></label>
            <label>For <select name="kind"><option value="nhs">NHS charge</option><option value="private">Private prescription</option><option value="otc">Bought without a prescription</option></select></label>
            <label>Note <span class="muted small">(optional)</span><input name="note" maxlength="200"></label>
            <button class="btn btn-primary" type="submit">Add</button></form>
    </details>
    <?php if ($payments): ?>
    <details class="q-edit"><summary>Payments (<?= count($payments) ?>)</summary>
        <ul class="plain"><?php foreach ($payments as $pay): ?>
            <li class="logline"><span><strong><?= h(date('j M Y', strtotime($pay['paid_on']))) ?></strong> &pound;<?= number_format((float) $pay['amount'], 2) ?>
                <span class="muted small"><?= (int) $pay['items'] ?> item<?= (int) $pay['items'] === 1 ? '' : 's' ?><?= $pay['kind'] !== 'nhs' ? ' · ' . h($pay['kind'] === 'private' ? 'private' : 'bought') : '' ?><?= $pay['note'] ? ' · ' . h($pay['note']) : '' ?></span></span>
                <form method="post" action="<?= h(url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="payment_delete"><input type="hidden" name="payment_id" value="<?= (int) $pay['id'] ?>">
                    <button class="btn-link danger small" type="submit">Delete</button></form></li>
        <?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($areaKey === 'screening' && ($gaps = screening_gaps($user))): ?>
<p class="flash flash-info">Because of the sex on your GP record, you may not be invited automatically for: <?= h(implode(', ', $gaps)) ?>.
    Ask your GP practice to arrange it. These reminders follow your body inventory.</p>
<?php endif; ?>
<?php if ($areaKey === 'screening' && (!$user['date_of_birth'] || (!$user['sex'] && body_inventory($user) === null))): ?>
<p class="flash flash-warning">Add your <?= !$user['date_of_birth'] ? 'date of birth' : '' ?><?= !$user['date_of_birth'] && !$user['sex'] && body_inventory($user) === null ? ' and ' : '' ?><?= !$user['sex'] && body_inventory($user) === null ? 'body inventory (or sex)' : '' ?>
    to <a href="<?= h(url('account.php#health-profile')) ?>">your health profile</a> to see which screening you're invited to and when.</p>
<?php endif; ?>

<?php if ($remindersOff): ?>
<p class="flash flash-warning">Reminders go to your calendar feed, which isn't turned on yet.
    <a href="<?= h(url('appointments.php#subscribe')) ?>">Turn on your calendar link</a> and add it to your phone's calendar to get the alerts.</p>
<?php endif; ?>

<?php ob_start(); /* ---------- checks and renewals ---------- */ ?>
<?php if ($checks || $area['checks'] || $areaKey === 'screening'): ?>
<section class="card" id="checks">
    <h2><?= ['screening' => 'Your screening', 'vaccinations' => 'Your vaccinations', 'womens' => 'Contraception and reviews'][$areaKey] ?? 'Checks and renewals' ?></h2>
    <?php if (!$checks): ?><p class="muted small"><?= $areaKey !== 'screening' ? 'Nothing added yet.' : ($user['sex'] === 'other'
        ? 'Tick the screening you need in <a href="' . h(url('account.php#health-profile')) . '">your health profile</a>.'
        : 'Nothing to show until your health profile has your sex.') ?></p><?php endif; ?>
    <ul class="plain">
    <?php foreach ($checks as $c):
        [$due, $note] = check_next_due($c, $user, $today);
        $status = check_status($due, $today);
        $editing = $checkForm && (int) $checkForm['id'] === (int) $c['id']; ?>
        <li class="check-item" id="check-<?= (int) $c['id'] ?>">
            <div class="logline">
                <span><strong><?= h($c['label']) ?></strong>
                    <?= check_badge($c, $status) ?>
                    <br><span class="small"><?= h(check_due_text($c, $due, $today) ?: $note) ?></span>
                    <span class="muted small"><?= $c['last_done'] ? ' · ' . h(['expiry' => check_lengths($c) ? 'started' : 'replaced', 'stock' => 'restocked', 'level' => 'collected', 'kit' => 'checked'][$c['track']] ?? 'last done') . ' ' . h((new DateTimeImmutable($c['last_done']))->format('j M Y')) : '' ?>
                        <?= $c['rule'] === 'autumn' ? ' · every autumn' : ($c['interval_days'] ? ' · ' . h(interval_label((int) $c['interval_days'])) : '') ?></span>
                    <?php if ($due && $note): ?><br><span class="muted small"><?= h($note) ?></span><?php endif; ?>
                    <?php if ($c['tip']): ?><br><span class="muted small"><?= h($c['tip']) ?></span><?php endif; ?>
                    <?php if ($c['notes']): ?><br><span class="small"><?= h($c['notes']) ?></span><?php endif; ?></span>
                <?php check_actions($c, $self, $today); ?>
            </div>
            <?php if ($c['medication_id'] && ($linked = find_medication((int) $c['medication_id'], $uid))): ?>
            <p class="small muted check-med">For <?= med_dot($linked['colour']) ?> <?= h($linked['name']) ?> <?= h($linked['strength']) ?></p>
            <?php endif; ?>
            <?php if ($c['track'] === 'expiry' && $c['unit'] && check_lengths($c)): ?><p class="small muted check-med"><?= h($c['unit']) ?> certificate</p><?php endif; ?>
            <?php if (check_has_results($c)): $results = check_results((int) $c['id']); ?>
            <div class="results">
                <?php if ($results): ?>
                <table class="compact results-table">
                    <thead><tr><th>Date</th><th>Result</th><th><span class="sr">Remove</span></th></tr></thead>
                    <tbody><?php foreach (array_slice($results, 0, 5) as $r): ?>
                        <tr><td class="nowrap"><?= h(date('j M Y', strtotime($r['taken_on']))) ?></td><td><strong><?= h($r['result']) ?></strong><?= $r['notes'] ? '<br><span class="muted small">' . h($r['notes']) . '</span>' : '' ?></td>
                            <td><form method="post" action="<?= h(url($self)) ?>" data-confirm="Delete this result?"><?= csrf_field() ?><input type="hidden" name="action" value="result_delete"><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="result_id" value="<?= (int) $r['id'] ?>">
                                <button class="btn-link danger small" type="submit">Delete</button></form></td></tr>
                    <?php endforeach; ?></tbody>
                </table>
                <?php endif; ?>
                <details class="q-edit"><summary>Add a result</summary>
                    <form method="post" action="<?= h(url($self)) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="result_add"><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>">
                        <label>Date <input type="date" name="taken_on" required max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($today->format('Y-m-d')) ?>"></label>
                        <label>Result <input name="result" maxlength="200" required placeholder="e.g. 0.6 mmol/L, or All normal"></label>
                        <label>Notes <span class="muted small">(optional)</span><input name="notes" maxlength="500"></label>
                        <button class="btn btn-primary" type="submit">Save</button></form>
                </details>
            </div>
            <?php endif; ?>
            <?php if ($c['track'] === 'kit' && ($items = kit_items($c))): ?>
            <ul class="kit-items">
                <?php foreach ($items as $it): [$st, $txt] = expiry_status($it['expiry']?->format('Y-m-d'), $today); ?>
                <li><?= h($it['name']) ?><?php if ($it['expiry']): ?> <span class="<?= $st ? 'warn' : 'muted' ?> small">&middot; <?= h(lcfirst($txt)) ?></span>
                    <?php elseif ($it['has_expiry']): ?> <span class="muted small">&middot; add expiry</span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <details class="q-edit"<?= $editing ? ' open' : '' ?>>
                <summary>Change</summary>
                <?= $editing ? form_errors($errors) : '' ?>
                <form method="post" action="<?= h(url($self)) ?>" class="stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="check_update"><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>">
                    <?php check_fields($editing ? $checkForm + ['rule' => $c['rule']] : $c); ?>
                    <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
                </form>
                <form method="post" action="<?= h(url($self)) ?>" class="inline-actions"<?= $c['rule'] === 'screening' ? '' : ' data-confirm="Remove ' . h($c['label']) . '?"' ?>>
                    <?= csrf_field() ?><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>">
                    <?php if ($c['rule'] === 'screening'): ?>
                    <input type="hidden" name="action" value="check_hide"><button class="btn btn-small" type="submit">Doesn't apply to me</button>
                    <?php else: ?>
                    <input type="hidden" name="action" value="check_delete"><button class="btn btn-small btn-danger" type="submit">Remove</button>
                    <?php endif; ?>
                </form>
            </details>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php if ($hiddenChecks): ?>
    <div class="hidden-checks muted small">Hidden:
        <?php foreach ($hiddenChecks as $c): ?>
        <form method="post" action="<?= h(url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="check_show"><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>">
            <button class="btn-link small" type="submit">Show <?= h($c['label']) ?></button></form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($areaKey === 'screening'):
        $missing = array_filter(SCREENING_PROGRAMMES, fn ($p, $k) => !in_array($k, $usedCheckKinds, true), ARRAY_FILTER_USE_BOTH); ?>
        <?php if ($missing): ?>
        <p class="muted small">Add another programme:</p>
        <div class="preset-list">
            <?php foreach ($missing as $kind => $p): ?>
            <form method="post" action="<?= h(url($self)) ?>" class="preset"><?= csrf_field() ?><input type="hidden" name="action" value="check_preset"><input type="hidden" name="kind" value="<?= h($kind) ?>">
                <button class="btn btn-small" type="submit">+ <?= h($p['label']) ?></button></form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($presets = array_diff_key($area['checks'], array_flip($usedCheckKinds))):
            $presetGroups = [];
            foreach ($presets as $kind => $p) {
                $presetGroups[$p['group'] ?? ''][$kind] = $p;
            }
            foreach ($presetGroups as $groupName => $presets): ?>
        <?php if ($groupName !== ''): ?><h3 class="preset-group"><?= h($groupName) ?></h3><?php endif; ?>
        <div class="preset-list">
            <?php foreach ($presets as $kind => $p): ?>
            <form method="post" action="<?= h(url($self)) ?>" class="preset"><?= csrf_field() ?><input type="hidden" name="action" value="check_preset"><input type="hidden" name="kind" value="<?= h($kind) ?>">
                <button class="btn" type="submit"><span>+ <?= h($p['label']) ?></span><span class="muted small"><?= h($p['hint'] ?? (isset($p['rule']) ? 'every autumn' : ((($p['track'] ?? '') === 'expiry' && isset($p['interval'])) ? 'use within ' . ($p['interval'] <= 90 ? $p['interval'] . ' days' : preg_replace('/^every /', '', interval_label($p['interval']))) . ' of opening' : null)
                    ?? (['expiry' => 'expiry date', 'stock' => 'keep stocked', 'level' => 'fill level'][$p['track'] ?? 'due']
                    ?? (isset($p['interval']) ? (($p['track'] ?? '') === 'kit' ? 'check ' : '') . interval_label($p['interval']) : 'set a date')))) ?></span></button></form>
            <?php endforeach; ?>
        </div>
        <?php endforeach; endif; ?>
        <details class="q-edit"<?= $checkForm && !$checkForm['id'] ? ' open' : '' ?>>
            <summary>Add your own</summary>
            <?= $checkForm && !$checkForm['id'] ? form_errors($errors) : '' ?>
            <form method="post" action="<?= h(url($self)) ?>" class="stack">
                <?= csrf_field() ?><input type="hidden" name="action" value="check_add">
                <?php check_fields($checkForm && !$checkForm['id'] ? $checkForm : ['label' => '', 'track' => 'due', 'interval_days' => null, 'rule' => null, 'last_done' => null,
                    'due_date' => null, 'quantity' => null, 'unit' => null, 'low_at' => null, 'per_week' => null, 'items' => null, 'notes' => '', 'remind' => 1], 'newcheck'); ?>
                <span class="inline-actions"><button class="btn btn-primary" type="submit">Add</button></span>
            </form>
        </details>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php $checksHtml = ob_get_clean(); ob_start(); /* ---------- routine ---------- */ ?>
<?php if ($tasks): ?>
<section class="card">
    <h2>Last 7 days</h2>
    <p class="muted small">Forgot to tick something? Tap a box to fill it in.</p>
    <div class="table-wrap">
    <table class="compact care-week">
        <thead><tr><th>Task</th><?php foreach ($days as $d): ?><th><?= h($d == $today ? 'Today' : $d->format('D')) ?><span class="muted small"><?= h($d->format('j')) ?></span></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($tasks as $t): foreach (care_times($t) as $slot => $time): ?>
            <tr>
                <th scope="row"><?= h($t['label']) ?><span class="muted small"><?= h($time) ?></span></th>
                <?php foreach ($days as $d): $done = care_done($logs, $t, $d, $slot); ?>
                <td>
                    <?php if (!care_due($t, $d)): ?>
                        <span class="muted" title="Not due">&ndash;</span>
                    <?php else: ?>
                    <form method="post" action="<?= h(url($self)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <input type="hidden" name="date" value="<?= h($d->format('Y-m-d')) ?>"><input type="hidden" name="slot" value="<?= (int) $slot ?>">
                        <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
                        <button class="q-check<?= $done ? ' is-done' : '' ?>" type="submit" aria-pressed="<?= $done ? 'true' : 'false' ?>"
                            aria-label="<?= h($t['label'] . ', ' . $time . ', ' . $d->format('l j F')) ?>"><?= $done ? '&#10003;' : '' ?></button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<section class="card" id="routine">
    <h2><?= $tasks ? 'Your routine' : ($area['presets'] ? 'Set up your routine' : 'Daily routine') ?></h2>
    <?php if (!$tasks): ?>
    <p class="muted small"><?= $area['presets'] ? 'Add tasks to tick off each day on the Today page.' : 'Anything you want to tick off regularly on the Today page.' ?></p>
    <?php endif; ?>
    <ul class="plain">
    <?php foreach ($tasks as $t): $editing = $form && (int) $form['id'] === (int) $t['id'];
        $nextDue = $t['every_days'] ? care_next_due($t, $today) : null; ?>
        <li class="care-task" id="task-<?= (int) $t['id'] ?>">
            <div class="logline">
                <span><strong><?= h($t['label']) ?></strong><?= $t['amount'] ? ' <span class="amount-chip">' . h($t['amount']) . '</span>' : '' ?>
                    <span class="muted small"><?= h(implode(', ', care_times($t))) ?> &middot; <?= h(care_repeat_label($t)) ?><?= $t['remind'] ? ' &middot; &#x1F514; reminder' : '' ?><?= $nextDue
                        ? ' &middot; next ' . h($nextDue == $today ? 'today' : ($nextDue == $today->modify('+1 day') ? 'tomorrow' : $nextDue->format('D j M'))) : '' ?></span>
                    <?php if ($t['tip']): ?><br><span class="muted small"><?= h($t['tip']) ?></span><?php endif; ?></span>
                <form method="post" action="<?= h(url($self)) ?>" data-confirm="Remove <?= h($t['label']) ?> and its ticks from your routine?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Remove</button>
                </form>
            </div>
            <details class="q-edit"<?= $editing ? ' open' : '' ?>>
                <summary>Change</summary>
                <?= $editing ? form_errors($errors) : '' ?>
                <form method="post" action="<?= h(url($self)) ?>" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <?php care_fields($editing ? $form : $t, 'task-' . $t['id']); ?>
                    <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
                </form>
            </details>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php if ($presets = array_diff_key($area['presets'], array_flip($usedKinds))): ?>
    <p class="muted small"><?= $tasks ? 'Add more:' : 'One tap adds a common task with suggested times. You can change them afterwards.' ?></p>
    <div class="preset-list">
        <?php foreach (array_keys($presets) as $kind): $p = care_preset($areaKey, $kind); ?>
        <form method="post" action="<?= h(url($self)) ?>" class="preset">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_preset"><input type="hidden" name="kind" value="<?= h($kind) ?>">
            <button class="btn" type="submit"><span>+ <?= h($p['label']) ?></span><span class="muted small"><?= h(implode(' · ', array_filter([
                $p['every'] ? care_every_label($p['every']) : ($p['days'] !== EVERY_DAY ? days_label($p['days']) : (count($p['times']) === 1 ? 'daily, ' . $p['times'][0] : count($p['times']) . ' times a day')),
                $p['amount'],
            ]))) ?><?= $p['remind'] ? ' &middot; &#x1F514;' : '' ?></span></button>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <details class="q-edit"<?= $form && !$form['id'] ? ' open' : '' ?>>
        <summary>Add your own</summary>
        <?= $form && !$form['id'] ? form_errors($errors) : '' ?>
        <form method="post" action="<?= h(url($self)) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <?php care_fields($form && !$form['id'] ? $form : ['label' => '', 'times' => '', 'days_mask' => EVERY_DAY, 'every_days' => null, 'starts_on' => null, 'amount' => '', 'tip' => '', 'remind' => 0], 'new'); ?>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Add task</button></span>
        </form>
    </details>
    <?php if ($areaKey === 'eyes'): ?>
    <p class="muted small">Use eye drops or ointment? <a href="<?= h(url('medication_edit.php')) ?>">Add them as a medication</a> (form: drops), so doses are on your Today list and stock is tracked.</p>
    <?php elseif ($areaKey === 'womens'): ?>
    <p class="muted small">Taking the pill, or HRT? <a href="<?= h(url('medication_edit.php')) ?>">Add it as a medication</a> so doses are on your Today list and missed ones are easy to spot.</p>
    <?php endif; ?>
</section>
<?php $routineHtml = ob_get_clean(); echo $routineFirst ? $routineHtml . $checksHtml : $checksHtml . $routineHtml; ?>

<?php if ($area['appointment']): ?>
<section class="card">
    <h2>Appointments</h2>
    <?php if ($next): ?>
        <p>Next <?= h($apptLabel) ?> appointment: <a href="<?= h(url('appointment.php?id=' . $next['id'])) ?>"><strong><?= h(local_time($next['starts_at'], 'l j F, H:i')) ?></strong></a>
            <?= $next['with_whom'] ? 'with ' . h($next['with_whom']) : '' ?></p>
    <?php else: ?>
        <p><?= $last ? 'Your last ' . h($apptLabel) . ' appointment in Pillbox was on ' . h(local_time($last['starts_at'], 'j F Y')) . '.' : 'No ' . h($apptLabel) . ' appointments in Pillbox yet.' ?></p>
        <a class="btn btn-small" href="<?= h(url('appointment_edit.php?kind=' . $area['appointment'])) ?>">+ Add <?= preg_match('/^[aeiou]/', $apptLabel) ? 'an' : 'a' ?> <?= h($apptLabel) ?> appointment</a>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2>Good to know</h2>
    <p><?= h($area['intro']) ?></p>
    <p class="muted"><?= h($area['help']) ?></p>
    <?php if ($user['care_prompt_hidden'] && !care_tasks($uid)): ?>
    <form method="post" action="<?= h(url($self)) ?>" class="inline-actions">
        <?= csrf_field() ?><input type="hidden" name="action" value="show_prompt">
        <button class="btn-link small" type="submit">Show care routines on the Today page again</button>
    </form>
    <?php endif; ?>
</section>
<?php render_footer();
