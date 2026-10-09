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
        $amount = $status === 'taken' ? (float) $schedule['dose_amount'] : 0.0;

        transaction(function () use ($schedule, $slotUtc, $status, $amount, $takenAt, $uid) {
            $existing = q('SELECT * FROM dose_logs WHERE schedule_id = ? AND scheduled_for = ? FOR UPDATE', [$schedule['id'], $slotUtc])->fetch();
            if ($existing) {
                if ($existing['status'] === 'taken') {
                    adjust_stock((int) $schedule['med_id'], (float) $existing['amount']);
                }
                q('UPDATE dose_logs SET status = ?, amount = ?, taken_at = ? WHERE id = ?', [$status, $amount, $takenAt, $existing['id']]);
            } else {
                q(
                    'INSERT INTO dose_logs (user_id, medication_id, schedule_id, scheduled_for, status, amount, taken_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $schedule['med_id'], $schedule['id'], $slotUtc, $status, $amount, $takenAt]
                );
            }
            if ($status === 'taken') {
                adjust_stock((int) $schedule['med_id'], -$amount);
            }
        });
        flash('success', $schedule['name'] . ($status === 'taken' ? ' marked as taken.' : ' marked as skipped.'));
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
