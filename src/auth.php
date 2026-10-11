<?php
declare(strict_types=1);

const LOGIN_MAX_FAILURES = 8;
const LOGIN_WINDOW_MINUTES = 15;

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
        if (!empty($_SESSION['uid'])) {
            $user = q('SELECT * FROM users WHERE id = ? AND is_active = 1 AND pending_approval = 0', [$_SESSION['uid']])->fetch() ?: null;
            // Signed out remotely ("sign out everywhere else", password change or reset)?
            if ($user !== null && (int) $user['session_version'] !== (int) ($_SESSION['sv'] ?? 0)) {
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
}

function finish_login_redirect(): never
{
    $target = safe_return($_SESSION['after_login'] ?? null);
    unset($_SESSION['after_login']);
    redirect($target);
}

function logout_session(): void
{
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
