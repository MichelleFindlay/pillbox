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

/** Full URL including scheme and host, e.g. for links used outside Pillbox (calendar feeds). */
function absolute_url(string $path = ''): string
{
    $base = rtrim((string) config('app.public_url', ''), '/');
    if ($base === '' && empty($_SERVER['HTTP_HOST'])) {
        // Scheduled tasks have no request to go on: use the address the site was last visited at.
        $base = rtrim((string) @file_get_contents(storage_path('site_url.txt')), '/');
    }
    if ($base === '') {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $base = (is_https() ? 'https' : 'http') . '://' . $host . config('app.base_path', '');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Remember the site's address (once per session) so emails sent by scheduled tasks can link back. */
function remember_site_url(): void
{
    if (config('app.public_url', '') !== '' || empty($_SERVER['HTTP_HOST']) || !empty($_SESSION['site_url_saved'])) {
        return;
    }
    $_SESSION['site_url_saved'] = true;
    $url = rtrim(absolute_url(), '/');
    if (@file_get_contents(storage_path('site_url.txt')) !== $url) {
        @file_put_contents(storage_path('site_url.txt'), $url, LOCK_EX);
    }
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
    return preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%.:+\-]*)?(\x23[a-z0-9-]+)?$#', $target) ? $target : $fallback;
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

/**
 * GET (or POST a form to) a JSON endpoint of an external service.
 * @throws RuntimeException with a user-presentable message
 */
function http_json(string $url, ?array $form = null, array $headers = [], string $service = 'the sign-in provider'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        throw new RuntimeException("Could not contact $service: " . curl_error($ch));
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Unexpected response from $service (HTTP $status).");
    }
    if ($status >= 400) {
        error_log("$service HTTP $status: " . substr((string) $body, 0, 1000));
        throw new RuntimeException("$service rejected the request: " . ($data['error_description'] ?? $data['error'] ?? "HTTP $status"));
    }
    return $data;
}

function send_security_headers(): void
{
    // Sign-in forms (linking, sign-out) may redirect to these identity providers.
    $formTargets = implode(' ', array_unique(array_merge(['https://login.microsoftonline.com'], oidc_csp_origins())));
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
        . "form-action 'self' $formTargets; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
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
    $idle = max(5, (int) config('app.session_idle_minutes', 480)) * 60;
    $cookie = [
        'path'     => config('app.base_path') ?: '/',
        'secure'   => is_https(),
        'httponly' => true,
        // Lax (not Strict) so the cookie survives the redirect back from Microsoft.
        'samesite' => 'Lax',
    ];

    // Keep sessions in our own folder so the server's housekeeping (which on many
    // hosts deletes sessions after 24 minutes) doesn't sign people out early.
    $dir = storage_path('sessions');
    if (is_dir($dir) || @mkdir($dir, 0700)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string) $idle);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('PILLBOX');
    // A persistent cookie, so closing the installed phone app doesn't sign you out.
    session_set_cookie_params(['lifetime' => $idle] + $cookie);
    session_start();

    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
    // Slide the cookie's expiry forward on every visit.
    setcookie(session_name(), session_id(), ['expires' => time() + $idle] + $cookie);
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
    if (acting_read_only() && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['sharing.php', 'logout.php'], true)) {
        flash('error', "You can look, but not change anything: you've been given view-only access.");
        redirect(safe_return(basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: ''), 'index.php'));
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

/** The time zone to show times in: home, or the destination while a trip is under way. */
function user_tz(?array $user = null): DateTimeZone
{
    $user ??= current_user();
    if ($trip = active_trip($user)) {
        try {
            return new DateTimeZone($trip['destination_tz']);
        } catch (Exception) {
            // fall back to home
        }
    }
    return home_tz($user);
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
    $dir = (string) config('app.storage_path', APP_ROOT . '/storage');
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
