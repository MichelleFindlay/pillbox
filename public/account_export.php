<?php
/*
 * "Download all my data": everything Pillbox stores about the signed-in user, as JSON.
 * The readable sections are for people; "backup" is the complete copy that "Import data" restores.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];

$medications = q('SELECT * FROM medications WHERE user_id = ? ORDER BY id', [$uid])->fetchAll();
foreach ($medications as &$med) {
    $med['schedules'] = q('SELECT * FROM medication_schedules WHERE medication_id = ? ORDER BY id', [$med['id']])->fetchAll();
}
unset($med);

$export = [
    'exported_at' => gmdate('c'),
    'note' => 'All date-times are UTC. Your timezone is shown under "account". Dose schedule times are local times.',
    'account' => [
        'display_name'   => $user['display_name'],
        'username'       => $user['username'],
        'email'          => $user['email'],
        'has_password'   => $user['password_hash'] !== null,
        'microsoft_linked' => $user['entra_oid'] !== null,
        'calendar_link_on' => $user['calendar_token'] !== null,
        'timezone'       => $user['timezone'],
        'date_of_birth'  => $user['date_of_birth'],
        'sex'            => $user['sex'],
        'screening_programmes' => $user['screening_programmes'],
        'care_categories' => user_care_areas($user),
        'is_admin'       => (bool) $user['is_admin'],
        'created_at'     => $user['created_at'],
        'last_login_at'  => $user['last_login_at'],
        'tracked_metrics' => array_keys(enabled_metrics($user)),
    ],
    'sign_in_accounts' => q('SELECT provider, email, created_at, last_used_at FROM user_identities WHERE user_id = ?', [$uid])->fetchAll(),
    'appointments'    => q('SELECT id, kind, title, starts_at, duration_minutes, with_whom, location, notes, remind_minutes, created_at FROM appointments WHERE user_id = ? ORDER BY starts_at', [$uid])->fetchAll(),
    'medication_changes' => q('SELECT c.medication_id, m.name, c.effective_date, c.kind, c.summary, c.reason, c.created_at FROM medication_changes c JOIN medications m ON m.id = c.medication_id WHERE c.user_id = ? ORDER BY c.effective_date', [$uid])->fetchAll(),
    'medications'     => $medications,
    'dose_log'        => q('SELECT id, medication_id, schedule_id, scheduled_for, status, amount, taken_at, notes, created_at FROM dose_logs WHERE user_id = ? ORDER BY taken_at', [$uid])->fetchAll(),
    'custom_measurements' => q('SELECT id, label, unit FROM custom_metrics WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(),
    'care_tasks'      => q('SELECT id, area, kind, label, times, days_mask, every_days, starts_on, amount, tip, remind, created_at FROM care_tasks WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(),
    'care_log'        => q('SELECT task_id, log_date, slot, done_at FROM care_logs WHERE user_id = ? ORDER BY log_date, task_id, slot', [$uid])->fetchAll(),
    'health_checks'   => q('SELECT id, area, kind, label, interval_days, rule, last_done, due_date, notes, remind, hidden, created_at FROM health_checks WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(),
    'periods'         => q('SELECT start_date, end_date, flow, notes FROM cycle_periods WHERE user_id = ? ORDER BY start_date', [$uid])->fetchAll(),
    'mind' => [
        'check_ins'      => q('SELECT logged_at, log_date, mood, energy, motivation, anxiety, tags, triggers, note FROM mind_checkins WHERE user_id = ? ORDER BY logged_at', [$uid])->fetchAll(),
        'questionnaires' => q('SELECT kind, answers, score, difficulty, taken_at FROM mind_questionnaires WHERE user_id = ? ORDER BY taken_at', [$uid])->fetchAll(),
        'journal'        => array_map(fn ($e) => ['fields' => json_decode((string) $e['fields'], true)] + $e,
            q('SELECT kind, prompt, body, fields, created_at, updated_at FROM mind_journal WHERE user_id = ? ORDER BY created_at', [$uid])->fetchAll()),
        'symptoms'       => q('SELECT id, name, active FROM mind_symptoms WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(),
        'symptom_ratings' => q('SELECT l.checkin_id, l.symptom_id, l.severity FROM mind_symptom_logs l JOIN mind_checkins c ON c.id = l.checkin_id WHERE c.user_id = ?', [$uid])->fetchAll(),
        'self_care'      => q('SELECT log_date, item FROM mind_selfcare WHERE user_id = ? ORDER BY log_date', [$uid])->fetchAll(),
        'activities'     => q('SELECT planned_for, planned_time, activity, status, enjoyment, achievement, note FROM mind_activities WHERE user_id = ? ORDER BY planned_for', [$uid])->fetchAll(),
        'lists'          => q('SELECT kind, text, appointment_id, due_date, done, created_at FROM mind_notes WHERE user_id = ? ORDER BY kind, created_at', [$uid])->fetchAll(),
        'medication_notes' => q('SELECT n.medication_id, m.name, n.kind, n.note, n.severity, n.created_at FROM med_notes n JOIN medications m ON m.id = n.medication_id WHERE n.user_id = ? ORDER BY n.created_at', [$uid])->fetchAll(),
        'coping_toolkit' => q('SELECT item FROM mind_toolkit WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(PDO::FETCH_COLUMN),
        'safety_plan'    => safety_plan($uid),
        'contacts'       => q('SELECT kind, name, detail, phone, text_to, text_body, helps_with, reach_out_days, last_contact FROM mind_contacts WHERE user_id = ? ORDER BY kind, sort, id', [$uid])->fetchAll(),
    ],
    'health_readings' => q('SELECT id, metric, value1, value2, value3, context, notes, recorded_at, source, created_at FROM health_readings WHERE user_id = ? ORDER BY recorded_at', [$uid])->fetchAll(),
    'backup' => export_backup($user),
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Content-Disposition: attachment; filename="pillbox-my-data-' . gmdate('Y-m-d') . '.json"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
