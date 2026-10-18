<?php
/*
 * Record, change or undo a dose. All dose buttons post here.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
require_post();
$uid = (int) $user['id'];
$return = safe_return(post('return'));

/*
 * Safety checks (minimum time between doses, an as-needed medicine's daily maximum): a dose that breaks
 * one isn't logged. Instead the person is shown why, with "I took it: log anyway". That button carries a
 * one-time code from this session, so the check can only be skipped by that confirmation, never by
 * simply sending override=1.
 */
$overridden = false;
if (post('override') !== '' && !empty($_SESSION['dose_override_code'])) {
    [$code, $at] = $_SESSION['dose_override_code'];
    $overridden = $at > time() - 600 && hash_equals($code, post('override'));
    if ($overridden) {
        unset($_SESSION['dose_override_code']);
    }
}
$refuse = function (string $text, array $fields) use ($uid, $return): never {
    $code = bin2hex(random_bytes(16));
    $_SESSION['dose_override_code'] = [$code, time()];
    $_SESSION['dose_override'] = ['user' => $uid, 'text' => $text, 'return' => $return, 'code' => $code,
        'fields' => array_intersect_key($fields, array_flip(['action', 'schedule_id', 'slot', 'at', 'taken_time', 'site', 'medication_id', 'amount', 'notes']))];
    redirect($return);
};
/** Refuse a dose taken too soon after the last one, unless they confirmed it. */
$tooSoon = function (array $med, string $takenAt, ?int $excludeLogId, array $fields) use (&$overridden, $refuse): void {
    if (!$overridden && ($soon = dose_too_soon($med, $takenAt, $excludeLogId))) {
        $refuse(too_soon_text($med, $soon), $fields);
    }
};

switch (post('action')) {
    case 'take':
    case 'skip':
        $status = post('action') === 'take' ? 'taken' : 'skipped';
        $schedule = q(
            'SELECT s.*, m.id AS med_id, m.name FROM medication_schedules s
             JOIN medications m ON m.id = s.medication_id
             WHERE s.id = ? AND m.user_id = ?' . private_med_filter() . '',
            [(int) post('schedule_id'), $uid]
        )->fetch();
        $slot = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', post('slot'), new DateTimeZone('UTC'));
        if (!$schedule || $slot === false) {
            not_found();
        }
        $slotUtc = $slot->format('Y-m-d H:i:s');
        $takenAt = post('at') === 'scheduled' ? $slotUtc : utc_now();
        // "Took it at 9:00, logging now": that time on the dose's own day (never in the future).
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', post('taken_time'))) {
            $at = local_to_utc(local_time($slotUtc, 'Y-m-d') . ' ' . post('taken_time'));
            $takenAt = $at && $at <= utc_now() ? $at : utc_now();
        }
        if ($status === 'taken') {
            $existingId = q('SELECT id FROM dose_logs WHERE schedule_id = ? AND scheduled_for = ?', [$schedule['id'], $slotUtc])->fetchColumn();
            $tooSoon(find_medication((int) $schedule['med_id'], $uid), $takenAt, $existingId ? (int) $existingId : null, $_POST);
        }
        record_dose($uid, $schedule, $slotUtc, $status, $takenAt, post('site') ?: null);
        flash('success', $schedule['name'] . ($status === 'taken' ? ' marked as taken.' : ' marked as skipped.'));
        break;

    case 'take_block':
        // "Take all" for a part of the day: every dose in it not already recorded. Ones well past
        // their time (missed) are recorded at their scheduled time, like their own Take buttons.
        $missedAfter = (int) config('doses.missed_after_minutes', 120);
        $count = 0;
        $held = [];
        foreach (array_slice((array) ($_POST['slots'] ?? []), 0, 50) as $item) {
            [$scheduleId, $slotStr] = array_pad(explode('|', (string) $item, 2), 2, '');
            $schedule = q('SELECT s.*, m.id AS med_id, m.form FROM medication_schedules s JOIN medications m ON m.id = s.medication_id
                           WHERE s.id = ? AND m.user_id = ? AND m.as_needed = 0' . private_med_filter() . '', [(int) $scheduleId, $uid])->fetch();
            $slot = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $slotStr, new DateTimeZone('UTC'));
            if (!$schedule || $slot === false
                || q('SELECT 1 FROM dose_logs WHERE schedule_id = ? AND scheduled_for = ?', [$schedule['id'], $slotStr])->fetch()) {
                continue;
            }
            $late = time() - $slot->getTimestamp() > $missedAfter * 60;
            $med = find_medication((int) $schedule['med_id'], $uid);
            if ($soon = dose_too_soon($med, $late ? $slotStr : utc_now())) {
                $held[] = too_soon_text($med, $soon);
                continue;
            }
            record_dose($uid, $schedule, $slotStr, 'taken', $late ? $slotStr : utc_now(), $schedule['form'] === 'injection' ? injection_site_suggestion((int) $schedule['med_id']) : null);
            $count++;
        }
        flash('success', $count ? ($count === 1 ? '1 dose' : "$count doses") . ' marked as taken.' : ($held ? 'Nothing logged.' : 'Those doses were already recorded.'));
        foreach ($held as $text) {
            flash('error', $text . ' Use its own Take button if you did take it.');
        }
        break;

    case 'prn':
        $med = find_medication((int) post('medication_id'), $uid);
        if (!$med || !$med['as_needed']) {
            not_found();
        }
        $amount = (float) post('amount');
        if ($amount <= 0 || $amount > 10000) {
            $amount = (float) ($med['prn_dose_amount'] ?? 1);
        }
        $tooSoon($med, utc_now(), null, $_POST);
        // The daily maximum for an as-needed medicine (today, in the person's own time).
        if (!$overridden && $med['prn_max_daily'] !== null) {
            $dayStart = local_today()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $used = (float) q("SELECT COALESCE(SUM(amount), 0) FROM dose_logs WHERE medication_id = ? AND status = 'taken' AND taken_at >= ?", [$med['id'], $dayStart])->fetchColumn();
            if ($used + $amount > (float) $med['prn_max_daily'] + 0.0001) {
                $refuse("That would be more than the daily maximum for {$med['name']} (" . dose_label($med['prn_max_daily'], $med['dose_unit']) . ' a day; '
                    . dose_label($used, $med['dose_unit']) . ' logged today). Check with a doctor or pharmacist before taking more.', $_POST);
            }
        }
        transaction(function () use ($med, $amount, $uid) {
            q(
                "INSERT INTO dose_logs (user_id, medication_id, status, amount, taken_at, notes) VALUES (?, ?, 'taken', ?, ?, ?)",
                [$uid, $med['id'], $amount, utc_now(), mb_substr(post('notes'), 0, 255) ?: null]
            );
            adjust_stock((int) $med['id'], -$amount);
        });
        flash('success', dose_label($amount, $med['dose_unit']) . ' of ' . $med['name'] . ' logged.');
        break;

    case 'undo':
        $log = q('SELECT * FROM dose_logs WHERE id = ? AND user_id = ?', [(int) post('log_id'), $uid])->fetch();
        if (!$log) {
            not_found();
        }
        transaction(function () use ($log) {
            if ($log['status'] === 'taken') {
                adjust_stock((int) $log['medication_id'], (float) $log['amount']);
            }
            q('DELETE FROM dose_logs WHERE id = ?', [$log['id']]);
        });
        flash('success', 'Dose record removed.');
        break;

    default:
        http_response_code(400);
        exit('Unknown action');
}

redirect($return);
