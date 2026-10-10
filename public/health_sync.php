<?php
/*
 * Apple Health sync endpoint for iPhone automations (Shortcuts or Health Auto Export).
 * POST JSON to health_sync.php?token=<secret>. The token can also be sent as
 * "Authorization: Bearer <secret>". See the Apple Health page for the formats.
 */
define('PILLBOX_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

function sync_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

$token = get('token');
if ($token === '' && preg_match('/^Bearer\s+([a-f0-9]{40})$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $token = strtolower($m[1]);
}
$user = preg_match('/^[a-f0-9]{40}$/', $token)
    ? q('SELECT * FROM users WHERE health_sync_token = ? AND is_active = 1', [$token])->fetch()
    : false;
if (!$user) {
    sync_reply(['ok' => false, 'error' => 'Unknown sync link. Create a new one on the Apple Health page in Pillbox.'], 404);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sync_reply(['ok' => false, 'error' => 'Send readings with POST.'], 405);
}

$body = (string) file_get_contents('php://input', false, null, 0, 50 * 1024 * 1024);
$json = json_decode($body, true);
if (!is_array($json)) {
    sync_reply(['ok' => false, 'error' => 'The request body must be JSON.'], 400);
}

try {
    $importer = new HealthImporter(home_tz($user));
    read_health_json($json, $importer);
    $result = save_health_rows((int) $user['id'], $importer->rows());
} catch (RuntimeException $e) {
    sync_reply(['ok' => false, 'error' => $e->getMessage()], 422);
}
sync_reply(['ok' => true, 'added' => $result['added'], 'updated' => $result['updated'],
    'already_had' => $result['unchanged'], 'skipped' => $importer->invalid, 'metrics' => $result['metrics']]);
