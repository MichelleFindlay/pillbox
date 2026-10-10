<?php
/*
 * JSON medicine search against NHS dm+d, used by the medication form.
 */
require __DIR__ . '/../src/bootstrap.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!dmd_enabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'The NHS medicines dictionary is not configured.']);
    exit;
}

$term = get('q');
if (mb_strlen($term) < 3 || mb_strlen($term) > 100) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    echo json_encode(['results' => dmd_search($term)]);
} catch (RuntimeException $e) {
    error_log('dm+d search failed: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Could not search the NHS medicines dictionary just now. You can still type the details in yourself.']);
}
