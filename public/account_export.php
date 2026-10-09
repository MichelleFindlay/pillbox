<?php
/*
 * "Download all my data": everything Pillbox stores about the signed-in user, as JSON.
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
        'sign_in'        => $user['auth_provider'] === 'entra' ? 'microsoft' : ($user['entra_oid'] ? 'password + microsoft' : 'password'),
        'timezone'       => $user['timezone'],
        'is_admin'       => (bool) $user['is_admin'],
        'created_at'     => $user['created_at'],
        'last_login_at'  => $user['last_login_at'],
        'tracked_metrics' => array_keys(enabled_metrics($user)),
    ],
    'medications'     => $medications,
    'dose_log'        => q('SELECT id, medication_id, schedule_id, scheduled_for, status, amount, taken_at, notes, created_at FROM dose_logs WHERE user_id = ? ORDER BY taken_at', [$uid])->fetchAll(),
    'custom_measurements' => q('SELECT id, label, unit FROM custom_metrics WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(),
    'health_readings' => q('SELECT id, metric, value1, value2, value3, context, notes, recorded_at, created_at FROM health_readings WHERE user_id = ? ORDER BY recorded_at', [$uid])->fetchAll(),
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Content-Disposition: attachment; filename="pillbox-my-data-' . gmdate('Y-m-d') . '.json"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
