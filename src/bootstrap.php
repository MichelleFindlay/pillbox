<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['APP_CONFIG'] = require APP_ROOT . '/config/config.php';

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/entra.php';
require APP_ROOT . '/src/nhs_terminology.php';
require APP_ROOT . '/src/medications.php';
require APP_ROOT . '/src/metrics.php';
require APP_ROOT . '/src/charts.php';
require APP_ROOT . '/src/layout.php';

error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
date_default_timezone_set('UTC');

set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $detail = config('app.debug') ? '<pre>' . h((string) $e) . '</pre>' : '';
    $dbConnectError = $e instanceof PDOException
        && (in_array((string) $e->getCode(), ['2002', '1045', '1049', 'HY000'], true) || str_contains($e->getMessage(), 'Access denied'));
    $message = $dbConnectError
        ? 'Pillbox could not connect to the MySQL database. Check the db settings in config/config.local.php.'
        : 'The error has been logged.';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<h1>Something went wrong</h1><p>' . h($message) . '</p>' . $detail;
});

send_security_headers();
start_session();
ensure_schema();
