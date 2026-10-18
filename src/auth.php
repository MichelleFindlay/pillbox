<?php
declare(strict_types=1);

const LOGIN_MAX_FAILURES = 8;
const LOGIN_WINDOW_MINUTES = 15;
const REMEMBER_COOKIE = 'PILLBOX_DEVICE';

/**
 * The user whose Pillbox is being shown: the signed-in user, or someone who has shared
 * theirs with them (see sharing.php), with '_acting' => [real user, level, mind] added.
 * Scheduled tasks set it per user with set_current_user().
 */
function current_user(): ?array
{
    if (array_key_exists('PILLBOX_TASK_USER', $GLOBALS)) {
        return $GLOBALS['PILLBOX_TASK_USER'];
    }
    static $loaded = false;
    static $user = null;
    if (!$loaded) {
        $loaded = true;
        if (empty($_SESSION['uid'])) {
            remembered_device_sign_in(); // a remembered device: sign back in without asking
        }
        if (!empty($_SESSION['uid'])) {
            $user = q('SELECT * FROM users WHERE id = ? AND is_active = 1 AND pending_approval = 0', [$_SESSION['uid']])->fetch() ?: null;
            // Signed out remotely ("sign out everywhere else", password change or reset)?
            if ($user !== null && (int) $user['session_version'] !== (int) ($_SESSION['sv'] ?? 0)) {
                $user = null;
            }
            // Signed out individually, from the "Active sessions" list on another device?
            if ($user !== null && !empty($_SESSION['session_row_id']) && !touch_session((int) $_SESSION['session_row_id'])) {
                $user = null;
            }
            if ($user === null) {
                logout_session();
            } elseif (!empty($_SESSION['acting_for'])) {
                $user = acting_user($user, (int) $_SESSION['acting_for']) ?? $user;
            }
        }
    }
    return $user;
}

/** For scheduled tasks: make helpers (time zones, ...) work for this user. */
function set_current_user(?array $user): void
{
    $GLOBALS['PILLBOX_TASK_USER'] = $user;
}

/** The signed-in person, even while looking at someone else's Pillbox. */
function real_user(): ?array
{
    $user = current_user();
    return $user['_acting']['by'] ?? $user;
}

/** The owner's account, if $real may look at it; null (and stop acting) otherwise. */
function acting_user(array $real, int $ownerId): ?array
{
    $share = q('SELECT s.*, u.id AS uid FROM data_shares s JOIN users u ON u.id = s.owner_id AND u.is_active = 1
                WHERE s.owner_id = ? AND s.grantee_id = ?', [$ownerId, $real['id']])->fetch();
    if (!$share) {
        unset($_SESSION['acting_for']);
        return null;
    }
    $owner = q('SELECT * FROM users WHERE id = ?', [$ownerId])->fetch();
    if ($share['expires_on'] && $share['expires_on'] < (new DateTimeImmutable('today', home_tz($owner)))->format('Y-m-d')) {
        unset($_SESSION['acting_for']);
        flash('error', "Your access to {$owner['display_name']}'s Pillbox ended on " . date('j F', strtotime($share['expires_on'])) . '.');
        return null;
    }
    return $owner + ['_acting' => ['by' => $real, 'perms' => share_permissions($share), 'share_id' => (int) $share['id']]];
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $target = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $_SESSION['after_login'] = safe_return($target . ($query !== '' ? '?' . $query : ''));
        redirect('login.php');
    }
    // First login: the setup wizard comes first.
    if (!isset($user['_acting']) && $user['onboarded_at'] === null
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['welcome.php', 'logout.php', 'help.php', 'breathe.php', 'sharing.php'], true)) {
        redirect('welcome.php');
    }
    // Looking at someone else's Pillbox: only the parts and actions they've been given.
    if (isset($user['_acting'])) {
        $need = share_page_need(basename($_SERVER['SCRIPT_NAME'] ?? ''), is_post(), post('action'));
        if ($need === false) {
            flash('error', "That's only available in your own Pillbox. Use \"Back to mine\" first.");
            redirect('index.php');
        }
        if ($need !== null && !share_can($need[0], $need[1], $user)) {
            flash('error', is_post() ? "You can see this, but haven't been given permission to change it." : "That part of {$user['display_name']}'s Pillbox hasn't been shared with you.");
            redirect(is_post() ? safe_return(basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: ''), 'index.php') : 'index.php');
        }
    }
    return $user;
}

/** Stop someone with shared access doing something only the owner should (sync links, PINs, deleting). */
function owner_only(string $what = 'That'): void
{
    if (isset(current_user()['_acting'])) {
        flash('error', "$what can only be done by the owner of this Pillbox.");
        redirect(safe_return(basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: ''), 'index.php'));
    }
}



function require_admin(): array
{
    $user = require_login();
    if (!$user['is_admin']) {
        http_response_code(403);
        render_header('Forbidden');
        echo '<section class="card"><h1>Admins only</h1><p>You do not have access to this page.</p></section>';
        render_footer();
        exit;
    }
    return $user;
}

function login_user(array $user, ?string $method = null): void
{
    // A sign-up waiting for an admin: no session until it's approved.
    if (!empty($user['pending_approval'])) {
        flash('success', 'Thanks, ' . $user['display_name'] . '. Your account is waiting for an administrator to approve it.'
            . ($user['email'] && mail_enabled() ? ' You will get an email at ' . $user['email'] . ' when it is ready.' : ' Try signing in again later.'));
        redirect('login.php');
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['provider'] = $method ?? $user['auth_provider'];
    $_SESSION['sv'] = (int) ($user['session_version'] ?? 0);
    $_SESSION['login_at'] = time();
    unset($_SESSION['csrf']);
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
    track_session((int) $user['id']);
    // "Remember this device", ticked on the sign-in page (kept in the session through single sign-on).
    $remember = !empty($_SESSION['remember_device']);
    unset($_SESSION['remember_device']);
    if ($remember && remember_days() > 0 && empty($user['is_demo'])) {
        remember_device((int) $user['id']);
    }
}

/* ---------- Remembered devices ---------- */

/** How long a remembered device stays signed in without being used (0: the option is off). */
function remember_days(): int
{
    return max(0, min(400, (int) config('app.remember_days', 90)));
}

function remember_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => config('app.base_path') ?: '/',
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** "Firefox on Windows", "Safari on iPhone" */
function device_label(string $agent): string
{
    $os = match (true) {
        (bool) preg_match('/iPhone/i', $agent) => 'iPhone', (bool) preg_match('/iPad/i', $agent) => 'iPad',
        (bool) preg_match('/Android/i', $agent) => 'Android', (bool) preg_match('/Windows/i', $agent) => 'Windows',
        (bool) preg_match('/Macintosh|Mac OS/i', $agent) => 'Mac', (bool) preg_match('/CrOS/i', $agent) => 'ChromeOS',
        (bool) preg_match('/Linux/i', $agent) => 'Linux', default => 'a device',
    };
    $browser = match (true) {
        (bool) preg_match('/Edg\//', $agent) => 'Edge', (bool) preg_match('/Firefox|FxiOS/', $agent) => 'Firefox',
        (bool) preg_match('/SamsungBrowser/', $agent) => 'Samsung Internet', (bool) preg_match('/Chrome|CriOS/', $agent) => 'Chrome',
        (bool) preg_match('/Safari/', $agent) => 'Safari', default => 'Browser',
    };
    return "$browser on $os";
}

/**
 * Remember this browser: a random token in a long-lived cookie, of which only a hash is stored.
 * Each use pushes the expiry back, so a device used often stays signed in.
 */
function remember_device(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + remember_days() * 86400;
    q('INSERT INTO remembered_devices (user_id, selector, validator_hash, label, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)',
        [$userId, $selector, hash('sha256', $validator), device_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), gmdate('Y-m-d H:i:s', $expires)]);
    $_SESSION['device_id'] = (int) db()->lastInsertId();
    remember_cookie("$selector.$validator", $expires);
}

/** Signed out, but this browser is remembered: sign back in. */
function remembered_device_sign_in(): void
{
    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie === '' || PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    [$selector, $validator] = array_pad(explode('.', $cookie, 2), 2, '');
    $device = preg_match('/^[a-f0-9]{24}$/', $selector) && preg_match('/^[a-f0-9]{64}$/', $validator) && remember_days() > 0
        ? q('SELECT d.*, u.session_version FROM remembered_devices d JOIN users u ON u.id = d.user_id AND u.is_active = 1 AND u.pending_approval = 0
             WHERE d.selector = ? AND d.expires_at > UTC_TIMESTAMP()', [$selector])->fetch()
        : false;
    if (!$device || !hash_equals($device['validator_hash'], hash('sha256', $validator))) {
        if ($device) {
            q('DELETE FROM remembered_devices WHERE id = ?', [$device['id']]); // a guessed or copied token: stop it working
        }
        remember_cookie('', time() - 3600);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $device['user_id'];
    $_SESSION['provider'] = 'remembered';
    $_SESSION['sv'] = (int) $device['session_version'];
    $_SESSION['login_at'] = time();
    $_SESSION['device_id'] = (int) $device['id'];
    track_session((int) $device['user_id']);
    $expires = time() + remember_days() * 86400;
    q('UPDATE remembered_devices SET last_used_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $expires), $device['id']]);
    remember_cookie($cookie, $expires);
}

/** Forget this browser (signing out). */
function forget_this_device(): void
{
    $selector = explode('.', (string) ($_COOKIE[REMEMBER_COOKIE] ?? ''))[0];
    if ($selector !== '') {
        q('DELETE FROM remembered_devices WHERE selector = ?', [$selector]);
        remember_cookie('', time() - 3600);
    }
}

/* ---------- Active sessions ---------- */

/** Record this sign-in for the "Active sessions" list (Account > Password & security). */
function track_session(int $userId): void
{
    $ip = client_ip();
    q('INSERT INTO user_sessions (user_id, label, ip, location) VALUES (?, ?, ?, ?)',
        [$userId, device_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), $ip, geoip_lookup($ip)]);
    $_SESSION['session_row_id'] = (int) db()->lastInsertId();
}

/**
 * Approximate "City, Country" for an IP address, if config 'app.geoip_lookup' is turned on:
 * it's off by default, since it sends the IP address to a third party (ipapi.co) that Pillbox
 * otherwise never shares anything with. Never looks up a private/local address. Fails silently
 * (a slow or unreachable lookup should never hold up signing in).
 */
function geoip_lookup(string $ip): ?string
{
    if (!config('app.geoip_lookup', false) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }
    $ch = curl_init('https://ipapi.co/' . rawurlencode($ip) . '/json/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['User-Agent: Pillbox/' . pillbox_version()]]);
    $body = curl_exec($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data) || !empty($data['error']) || empty($data['city'])) {
        return null;
    }
    $parts = array_filter([(string) $data['city'], (string) ($data['country_code'] ?? $data['country_name'] ?? '')]);
    return $parts ? implode(', ', $parts) : null;
}

/**
 * Keep a session's "last active" time fresh, and check it hasn't been individually signed out
 * from another device's "Active sessions" list. @return bool still valid
 */
function touch_session(int $sessionRowId): bool
{
    $exists = (bool) q('SELECT 1 FROM user_sessions WHERE id = ?', [$sessionRowId])->fetch();
    if ($exists) {
        q('UPDATE user_sessions SET last_active_at = UTC_TIMESTAMP() WHERE id = ? AND last_active_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE', [$sessionRowId]);
    }
    return $exists;
}

function finish_login_redirect(): never
{
    $target = safe_return($_SESSION['after_login'] ?? null);
    unset($_SESSION['after_login']);
    redirect($target);
}

function logout_session(): void
{
    if (!empty($_SESSION['session_row_id'])) {
        q('DELETE FROM user_sessions WHERE id = ?', [(int) $_SESSION['session_row_id']]);
    }
    $_SESSION = [];
    session_regenerate_id(true);
}

function login_is_throttled(string $username): bool
{
    $row = q(
        'SELECT
            SUM(username = ?) AS by_user,
            SUM(ip = ?) AS by_ip
         FROM login_attempts
         WHERE attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE AND (username = ? OR ip = ?)',
        [$username, client_ip(), LOGIN_WINDOW_MINUTES, $username, client_ip()]
    )->fetch();
    return (int) $row['by_user'] >= LOGIN_MAX_FAILURES || (int) $row['by_ip'] >= LOGIN_MAX_FAILURES * 3;
}

/**
 * Re-confirm the current password before a sensitive change (adding a new authenticator app or
 * security key): an active session alone isn't enough, in case it was hijacked or left open on
 * a shared device. Throttled separately from sign-in attempts, since the account is already
 * authenticated here.
 */
function verify_step_up_password(array $user, string $password): bool
{
    $tries = $_SESSION['stepup_tries'] ?? ['n' => 0, 'at' => 0];
    if ($tries['n'] >= 5 && $tries['at'] > time() - 900) {
        return false;
    }
    $ok = $user['password_hash'] && password_verify($password, $user['password_hash']);
    if ($ok) {
        unset($_SESSION['stepup_tries']);
    } else {
        $_SESSION['stepup_tries'] = ['n' => ($tries['at'] > time() - 900 ? $tries['n'] : 0) + 1, 'at' => time()];
    }
    return $ok;
}

/**
 * Check a local username/password. Returns the user row on success or an error message.
 */
function attempt_local_login(string $username, string $password): array|string
{
    $username = mb_strtolower(trim($username));
    if ($username === '' || $password === '') {
        return 'Enter your username and password.';
    }
    if (login_is_throttled($username)) {
        return 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    }

    $user = q("SELECT * FROM users WHERE username = ? AND auth_provider = 'local'", [$username])->fetch();
    // Hash anyway when the user does not exist so response timing does not reveal it.
    $hash = $user['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
    $ok = $user && password_verify($password, $hash);

    if (!$ok || !$user['is_active']) {
        q('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', [client_ip(), $username]);
        return $ok ? 'This account has been disabled.' : 'Incorrect username or password.';
    }

    q('DELETE FROM login_attempts WHERE username = ?', [$username]);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return $user;
}

/**
 * Can people sign in with a Pillbox username and password?
 * Off if 'allow_local_login' is false, or if 'disable_local_login_when_sso' is on and
 * single sign-on (Microsoft or any OIDC provider) is enabled and configured.
 */
function local_login_enabled(): bool
{
    if (!config('app.allow_local_login', true)) {
        return false;
    }
    // The option lives under 'app'; earlier versions had it under 'entra', which still works.
    $disable = config('app.disable_local_login_when_sso', config('entra.disable_local_login_when_sso', false));
    return !($disable && sso_enabled());
}

/* ---------- Forgotten password ---------- */

const RESET_MINUTES = 60;

/** Can people reset a forgotten password by email here? */
function password_reset_enabled(): bool
{
    return local_login_enabled() && mail_enabled();
}

/**
 * "Forgotten password": email a reset link to the account with this username or email address,
 * if there is one with a password and an email. Says nothing about whether there is (so it can't
 * be used to find out who has an account), and sends at most 3 links an hour to an account.
 */
function send_password_reset(string $who): void
{
    $who = mb_strtolower(trim($who));
    q('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', [client_ip(), 'reset:' . mb_substr($who, 0, 90)]);
    $user = q("SELECT * FROM users WHERE (username = ? OR LOWER(email) = ?) AND auth_provider = 'local' AND password_hash IS NOT NULL
               AND is_active = 1 AND is_demo = 0 AND email IS NOT NULL AND email <> '' ORDER BY username = ? DESC LIMIT 1", [$who, $who, $who])->fetch();
    if (!$user || q('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [$user['id']])->fetchColumn() >= 3) {
        return;
    }
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    q('INSERT INTO password_resets (user_id, selector, validator_hash, created_at, expires_at, ip) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?)',
        [$user['id'], $selector, hash('sha256', $validator), RESET_MINUTES, client_ip()]);
    $link = absolute_url('reset.php?t=' . $selector . '.' . $validator);
    $app = (string) config('app.name');
    $text = "Hello {$user['display_name']},\n\nSomeone (hopefully you) asked to reset the password for your $app account, \"{$user['username']}\".\n\n"
        . "To choose a new password, open this link within " . RESET_MINUTES . " minutes:\n$link\n\n"
        . "If you didn't ask for this, you can ignore this email: your password hasn't changed.\n";
    $error = send_mail($user['email'], "Reset your $app password", $text, mail_html("Reset your $app password",
        '<p>Hello ' . h($user['display_name']) . ',</p><p>Someone (hopefully you) asked to reset the password for your ' . h($app) . ' account, <strong>' . h($user['username']) . '</strong>.</p>'
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#4b5e3a;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Choose a new password</a></p>'
        . '<p style="color:#666">The link works once, for ' . RESET_MINUTES . " minutes. If you didn't ask for this, ignore this email: your password hasn't changed.</p>"));
    if ($error) {
        error_log("Pillbox password reset email to user {$user['id']} failed: $error");
    }
}

/** The account a reset link belongs to, if it's valid and unused; null otherwise. */
function password_reset_user(string $token): ?array
{
    [$selector, $validator] = array_pad(explode('.', $token, 2), 2, '');
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }
    $row = q("SELECT r.validator_hash, u.* FROM password_resets r JOIN users u ON u.id = r.user_id AND u.is_active = 1 AND u.auth_provider = 'local'
              WHERE r.selector = ? AND r.expires_at > UTC_TIMESTAMP()", [$selector])->fetch();
    return $row && hash_equals($row['validator_hash'], hash('sha256', $validator)) ? $row : null;
}

/** Set the new password: every reset link for the account stops working, and it's signed out everywhere. */
function complete_password_reset(array $user, string $password): void
{
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    q('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);
    q('DELETE FROM login_attempts WHERE username = ?', [$user['username']]);
    bump_session_version((int) $user['id'], false);
    $app = (string) config('app.name');
    send_mail($user['email'], "Your $app password was changed", "Hello {$user['display_name']},\n\nThe password for your $app account, \"{$user['username']}\", has just been changed using a reset link, "
        . "and you've been signed out on all your devices.\n\nIf this wasn't you, contact whoever runs this $app straight away.\n",
        mail_html("Your $app password was changed", '<p>Hello ' . h($user['display_name']) . ',</p><p>The password for your ' . h($app) . ' account, <strong>' . h($user['username'])
            . "</strong>, has just been changed using a reset link, and you've been signed out on all your devices.</p><p>If this wasn't you, contact whoever runs this " . h($app) . ' straight away.</p>'));
}

function validate_new_password(string $password, string $confirm): ?string
{
    if (mb_strlen($password) < 10) {
        return 'Passwords must be at least 10 characters.';
    }
    if ($password !== $confirm) {
        return 'The two passwords do not match.';
    }
    return null;
}

function validate_username(string $username): ?string
{
    if (!preg_match('/^[a-z0-9._@-]{3,100}$/', $username)) {
        return 'Usernames are 3-100 characters: lowercase letters, numbers, and . _ @ -';
    }
    if (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetch()) {
        return 'That username is already taken.';
    }
    return null;
}

/**
 * Sign a user out on every device. If $keepCurrent, this browser stays signed in.
 */
function bump_session_version(int $userId, bool $keepCurrent): void
{
    q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
    // Remembered devices too (except this one, if it stays signed in).
    q('DELETE FROM remembered_devices WHERE user_id = ? AND id <> ?', [$userId, $keepCurrent ? (int) ($_SESSION['device_id'] ?? 0) : 0]);
    q('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?', [$userId, $keepCurrent ? (int) ($_SESSION['session_row_id'] ?? 0) : 0]);
    if ($keepCurrent) {
        session_regenerate_id(true);
        $_SESSION['sv'] = (int) q('SELECT session_version FROM users WHERE id = ?', [$userId])->fetchColumn();
    }
}

/** Would removing admin rights from (or deleting) this user leave Pillbox with no active admin? */
function is_last_admin(array $user): bool
{
    if (!$user['is_admin']) {
        return false;
    }
    return (int) q('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1 AND id <> ?', [$user['id']])->fetchColumn() === 0;
}

function user_count(): int
{
    return (int) q('SELECT COUNT(*) FROM users WHERE is_demo = 0')->fetchColumn();
}
