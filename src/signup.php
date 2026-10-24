<?php
declare(strict_types=1);

/*
 * People creating their own accounts ("Create an account" on the sign-in page), with a
 * password or through any single sign-on provider. Settings in config 'signup':
 * - enabled: sign-ups open (otherwise only admins add people, plus providers set to auto_provision)
 * - require_approval: new accounts can't sign in until an admin approves them on the Admin page
 * - notify_admins: email admins about each new sign-up (needs the mail settings)
 * Providers with auto_provision on are trusted directories: their accounts aren't sign-ups.
 */

const SIGNUP_MAX_PER_HOUR = 5;

function signups_open(): bool
{
    return (bool) config('signup.enabled', false);
}

function signup_needs_approval(): bool
{
    return (bool) config('signup.require_approval', true);
}

/** How someone signed up, for the Admin page and emails ('password', 'entra' or 'oidc:<key>'). */
function signup_method_label(?string $method): string
{
    return match (true) {
        $method === 'password' => 'Username and password',
        $method === 'entra' => 'Microsoft',
        is_string($method) && str_starts_with($method, 'oidc:') => oidc_provider(substr($method, 5))['name'] ?? substr($method, 5),
        default => 'Added by an admin',
    };
}

/**
 * Mark a just-created account as a sign-up: waiting for approval if that's on (never the
 * first account, which is the administrator), then tell the admins.
 */
function signup_created(array $user, string $method): array
{
    $pending = signup_needs_approval() && !$user['is_admin'] ? 1 : 0;
    q('UPDATE users SET signup_method = ?, pending_approval = ?, terms_accepted_at = UTC_TIMESTAMP() WHERE id = ?', [$method, $pending, $user['id']]);
    $user = q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch();
    notify_admins_of_signup($user);
    return $user;
}

/** Email every admin with an address about a new sign-up, if that's switched on. */
function notify_admins_of_signup(array $user): void
{
    if (!config('signup.notify_admins', true) || !mail_enabled()) {
        return;
    }
    $pending = (bool) $user['pending_approval'];
    $link = absolute_url('admin.php') . '#signups';
    $who = $user['display_name'] . ($user['email'] ? ' (' . $user['email'] . ')' : ($user['username'] ? ' (' . $user['username'] . ')' : ''));
    $how = signup_method_label($user['signup_method']);
    $subject = ($pending ? 'Sign-up waiting for approval: ' : 'New sign-up: ') . $user['display_name'];
    $text = "$who has created a " . config('app.name', 'Pillbox') . " account ($how).\n\n"
        . ($pending ? "They can't sign in until an admin approves them:\n$link\n" : "They can use it straight away. Manage accounts on the Admin page:\n$link\n");
    $html = mail_html($subject, '<p><strong>' . h($user['display_name']) . '</strong>' . ($user['email'] ? ' &lt;' . h($user['email']) . '&gt;' : '')
        . ' has created an account using ' . h($how) . '.</p>'
        . '<p>' . ($pending ? "They can't sign in until an admin approves them." : 'They can use it straight away.') . '</p>'
        . '<p><a href="' . h($link) . '" style="display:inline-block;background:#4a5d3a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">'
        . ($pending ? 'Review sign-ups' : 'Open the Admin page') . '</a></p>');
    foreach (q("SELECT email FROM users WHERE is_admin = 1 AND is_active = 1 AND pending_approval = 0 AND id <> ? AND email IS NOT NULL AND email <> ''", [$user['id']]) as $admin) {
        send_mail($admin['email'], $subject, $text, $html);
    }
}

/** @return array<int, array> accounts waiting for approval, oldest first */
function pending_signups(): array
{
    return q('SELECT * FROM users WHERE pending_approval = 1 AND is_demo = 0 ORDER BY created_at, id')->fetchAll();
}

/** Let a waiting account in, and tell them by email if we can. */
function approve_signup(array $user): void
{
    q('UPDATE users SET pending_approval = 0 WHERE id = ?', [$user['id']]);
    if ($user['email'] && mail_enabled()) {
        $app = (string) config('app.name', 'Pillbox');
        $link = absolute_url('login.php');
        send_mail($user['email'], "Your $app account is ready",
            "Hello {$user['display_name']},\n\nYour $app account has been approved. Sign in here:\n$link\n",
            mail_html("Your $app account is ready", '<p>Hello ' . h($user['display_name']) . ',</p><p>Your account has been approved.</p>'
                . '<p><a href="' . h($link) . '" style="display:inline-block;background:#4a5d3a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Sign in</a></p>'));
    }
}

/** Turn down a waiting account: it's deleted (it can't have any data yet). */
function decline_signup(array $user): void
{
    q('DELETE FROM users WHERE id = ? AND pending_approval = 1', [$user['id']]);
}

/** Has this address made too many sign-ups recently? */
function signup_is_throttled(): bool
{
    return (int) q("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = '#signup' AND attempted_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR",
        [client_ip()])->fetchColumn() >= SIGNUP_MAX_PER_HOUR;
}

/**
 * Create an account with a username and password from the sign-up form.
 * @return array|string the new user, or what's wrong
 */
function create_local_signup(string $name, string $username, string $email, string $password, string $confirm): array|string
{
    $errors = [];
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Enter your name.';
    }
    // "That username is already taken" says an account exists, so it can only be asked so often from one
    // address: enough for someone choosing a name, not for checking a list of them.
    $probes = (int) q("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = '#signup-taken' AND attempted_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [client_ip()])->fetchColumn();
    if ($probes >= 10) {
        return 'Too many tries from here recently. Please try again later.';
    }
    if ($e = validate_username($username)) {
        if (str_contains($e, 'taken')) {
            q("INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, '#signup-taken', UTC_TIMESTAMP())", [client_ip()]);
        }
        $errors[] = $e;
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255)) {
        $errors[] = 'Enter a valid email address, or leave it empty.';
    }
    if ($e = validate_new_password($password, $confirm)) {
        $errors[] = $e;
    }
    if ($errors) {
        return implode(' ', $errors);
    }
    if (signup_is_throttled()) {
        return 'Too many accounts have been created from here recently. Please try again later.';
    }
    q("INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, '#signup', UTC_TIMESTAMP())", [client_ip()]);
    q("INSERT INTO users (username, email, display_name, password_hash, auth_provider, is_admin, timezone) VALUES (?, ?, ?, ?, 'local', ?, ?)",
        [$username, $email ?: null, $name, password_hash($password, PASSWORD_DEFAULT), 0, config('app.default_timezone')]); // never the administrator: that's set up with the setup code
    return signup_created(q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch(), 'password');
}
