<?php
declare(strict_types=1);

/** Read a config value using dot notation, e.g. config('entra.client_id'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['APP_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return config('app.base_path', '') . '/' . ltrim($path, '/');
}

/** URL for a static asset with a cache-busting version. */
function asset(string $path): string
{
    $file = APP_ROOT . '/public/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url($path) . '?v=' . $version;
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/**
 * Only allow redirects back to one of our own pages, e.g. "history.php?from=2026-01-01".
 */
function safe_return(?string $target, string $fallback = 'index.php'): string
{
    $target = (string) $target;
    return preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%.:+\-]*)?$#', $target) ? $target : $fallback;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return config('app.trust_proxy') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string
{
    if (config('app.trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
        . "form-action 'self' https://login.microsoftonline.com; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function start_session(): void
{
    session_name('PILLBOX');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => config('app.base_path') ?: '/',
        'secure'   => is_https(),
        'httponly' => true,
        // Lax (not Strict) so the cookie survives the redirect back from Microsoft.
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    $idle = (int) config('app.session_idle_minutes', 480) * 60;
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired or the form was submitted twice. Go back, reload the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function get(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ---------- Dates & numbers ---------- */

function user_tz(?array $user = null): DateTimeZone
{
    $user ??= current_user();
    try {
        return new DateTimeZone($user['timezone'] ?? config('app.default_timezone'));
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Convert a UTC 'Y-m-d H:i:s' string to the user's local time, formatted. */
function local_time(?string $utc, string $format = 'D j M Y, H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(user_tz())->format($format);
}

/** Convert a local datetime (from <input type="datetime-local">) to UTC 'Y-m-d H:i:s'. Null if invalid. */
function local_to_utc(string $local): ?string
{
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $local, user_tz());
        if ($dt !== false) {
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function local_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', user_tz());
}

/** Parse a 'Y-m-d' date in the user's timezone, or return null. */
function parse_date(string $value): ?DateTimeImmutable
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, user_tz());
    return ($dt !== false && $dt->format('Y-m-d') === $value) ? $dt : null;
}

/** Format a decimal without pointless trailing zeros: 2.00 -> 2, 0.50 -> 0.5. */
function num(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $s = number_format((float) $value, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/** "1 tablet", "2 tablets", "5 ml". */
function dose_label(mixed $amount, string $unit): string
{
    $n = num($amount);
    $plurals = ['tablet', 'capsule', 'puff', 'drop', 'unit', 'patch', 'sachet', 'spray', 'application', 'injection', 'suppository', 'lozenge'];
    if ($n !== '1' && in_array($unit, $plurals, true)) {
        $unit .= $unit === 'patch' ? 'es' : 's';
    }
    return $n . ' ' . $unit;
}

function storage_path(string $file): string
{
    $dir = config('app.storage_path');
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir();
    }
    return rtrim($dir, '/') . '/' . $file;
}

function not_found(): never
{
    http_response_code(404);
    render_header('Not found');
    echo '<section class="card"><h1>Not found</h1><p>That item does not exist or is not yours.</p>'
        . '<p><a class="btn" href="' . h(url('index.php')) . '">Back to today</a></p></section>';
    render_footer();
    exit;
}
