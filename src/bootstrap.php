<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

if (!is_file(APP_ROOT . '/config/config.php')) {
    http_response_code(500);
    exit('<!doctype html><meta charset="utf-8"><title>Pillbox setup</title><h1>Pillbox needs its settings file</h1>'
        . '<p>Copy <code>config/config.example.php</code> to <code>config/config.php</code> and fill in your database details.</p>');
}
$GLOBALS['APP_CONFIG'] = require APP_ROOT . '/config/config.php';
// An empty or broken config.php (e.g. overwritten by an upload) returns 1, not the settings: say so clearly.
if (!is_array($GLOBALS['APP_CONFIG'])) {
    http_response_code(500);
    error_log('Pillbox: config/config.php did not return the settings array. It may be empty or damaged: restore it from a backup.');
    exit('<!doctype html><meta charset="utf-8"><title>Pillbox setup</title><h1>Pillbox\'s settings file is empty or damaged</h1>'
        . '<p><code>config/config.php</code> must start with <code>&lt;?php</code> and <code>return [</code> &hellip; <code>];</code> with your database details. '
        . 'It may have been overwritten by an upload. Restore it from a backup, or copy <code>config/config.example.php</code> and fill it in again.</p>');
}
// Site settings chosen by an admin in Pillbox (first-run setup or the Admin page) override config.php.
if (is_file(APP_ROOT . '/config/settings.php')) {
    $settings = require APP_ROOT . '/config/settings.php';
    if (is_array($settings)) { // a damaged settings.php is ignored, rather than stopping the site
        $GLOBALS['APP_CONFIG'] = array_replace_recursive($GLOBALS['APP_CONFIG'], $settings);
    }
    unset($settings);
}
$GLOBALS['APP_CONFIG']['app']['base_path'] = rtrim((string) ($GLOBALS['APP_CONFIG']['app']['base_path'] ?? ''), '/');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/version.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/signup.php';
require APP_ROOT . '/src/entra.php';
require APP_ROOT . '/src/oidc.php';
require APP_ROOT . '/src/nhs_terminology.php';
require APP_ROOT . '/src/medications.php';
require APP_ROOT . '/src/travel.php';
require APP_ROOT . '/src/appointments.php';
require APP_ROOT . '/src/care.php';
require APP_ROOT . '/src/health_checks.php';
require APP_ROOT . '/src/cycle.php';
require APP_ROOT . '/src/pregnancy.php';
require APP_ROOT . '/src/pathway.php';
require APP_ROOT . '/src/menopause.php';
require APP_ROOT . '/src/labs.php';
require APP_ROOT . '/src/gender.php';
require APP_ROOT . '/src/legal.php';
require APP_ROOT . '/src/pmdd.php';
require APP_ROOT . '/src/neuro.php';
require APP_ROOT . '/src/thyroid.php';
require APP_ROOT . '/src/diabetes.php';
require APP_ROOT . '/src/migraine.php';
require APP_ROOT . '/src/weather.php';
require APP_ROOT . '/src/allergies.php';
require APP_ROOT . '/src/privatehealth.php';
require APP_ROOT . '/src/policyscan.php';
require APP_ROOT . '/src/bp.php';
require APP_ROOT . '/src/docs.php';
require APP_ROOT . '/src/mind.php';
require APP_ROOT . '/src/mailer.php';
require APP_ROOT . '/src/webauthn.php';
require APP_ROOT . '/src/totp.php';
require APP_ROOT . '/src/demo.php';
require APP_ROOT . '/src/sharing.php';
require APP_ROOT . '/src/backup.php';
require APP_ROOT . '/src/updater.php';
require APP_ROOT . '/src/push.php';
require APP_ROOT . '/src/site_settings.php';
require APP_ROOT . '/src/connectors.php';
require APP_ROOT . '/src/tasks.php';
require APP_ROOT . '/src/metrics.php';
require APP_ROOT . '/src/health_import.php';
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
        ? 'Pillbox could not connect to the MySQL database. Check the db settings in config/config.php.'
        : 'The error has been logged.';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<h1>Something went wrong</h1><p>' . h($message) . '</p>' . $detail;
});

send_security_headers();
// Tidy addresses: send old ".php" page addresses to the tidy ones (feeds and sync URLs stay as they are).
if (pretty_urls() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && preg_match('#/([a-z_]+)\.php$#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), $m)
    && !in_array($m[1], ['calendar', 'health_sync', 'cron', 'webauthn', 'push', 'connect', 'manifest', 'photo'], true)) {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . url($m[1] . '.php' . ($query !== '' ? '?' . $query : '')), true, 301);
    exit;
}
if (!defined('PILLBOX_NO_SESSION')) {
    start_session();
}
ensure_schema();
if (!defined('PILLBOX_NO_SESSION')) {
    remember_site_url(); // after the schema, as it checks the user is an admin
}
