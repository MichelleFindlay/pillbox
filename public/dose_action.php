<?php
/*
 * Record, change or undo a dose. All dose buttons post here.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
require_post();
$uid = (int) $user['id'];
$return = safe_return(post('return'));

switch (post('action')) {
    case 'take':
    case 'skip':
        $status = post('action') === 'take' ? 'taken' : 'skipped';
        $schedule = q(
            'SELECT s.*, m.id AS med_id, m.name FROM medication_schedules s
             JOIN medications m ON m.id = s.medication_id
             WHERE s.id = ? AND m.user_id = ?',
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
        record_dose($uid, $schedule, $slotUtc, $status, $takenAt, post('site') ?: null);
        flash('success', $schedule['name'] . ($status === 'taken' ? ' marked as taken.' : ' marked as skipped.'));
        break;

    case 'take_block':
        // "Take all" for a part of the day: every dose in it not already recorded. Ones well past
        // their time (missed) are recorded at their scheduled time, like their own Take buttons.
        $missedAfter = (int) config('doses.missed_after_minutes', 120);
        $count = 0;
        foreach (array_slice((array) ($_POST['slots'] ?? []), 0, 50) as $item) {
            [$scheduleId, $slotStr] = array_pad(explode('|', (string) $item, 2), 2, '');
            $schedule = q('SELECT s.*, m.id AS med_id, m.form FROM medication_schedules s JOIN medications m ON m.id = s.medication_id
                           WHERE s.id = ? AND m.user_id = ? AND m.as_needed = 0', [(int) $scheduleId, $uid])->fetch();
            $slot = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $slotStr, new DateTimeZone('UTC'));
            if (!$schedule || $slot === false
                || q('SELECT 1 FROM dose_logs WHERE schedule_id = ? AND scheduled_for = ?', [$schedule['id'], $slotStr])->fetch()) {
                continue;
            }
            $late = time() - $slot->getTimestamp() > $missedAfter * 60;
            record_dose($uid, $schedule, $slotStr, 'taken', $late ? $slotStr : utc_now(), $schedule['form'] === 'injection' ? injection_site_suggestion((int) $schedule['med_id']) : null);
            $count++;
        }
        flash('success', $count ? ($count === 1 ? '1 dose' : "$count doses") . ' marked as taken.' : 'Those doses were already recorded.');
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
