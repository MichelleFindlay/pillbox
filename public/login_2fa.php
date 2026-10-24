<?php
/*
 * Second step of signing in locally when the account has an authenticator app and/or a
 * security key set up (Account > Password & security): the username and password were already
 * correct, and this checks the second factor before the session is granted.
 * $_SESSION['pending_2fa'] (set by login.php) says who.
 */
require __DIR__ . '/../src/bootstrap.php';

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending || $pending['at'] < time() - 300) {
    unset($_SESSION['pending_2fa']);
    redirect('login.php');
}
$uid = (int) $pending['uid'];

if (is_post() && post('action') === 'cancel') {
    require_post();
    unset($_SESSION['pending_2fa']);
    redirect('login.php');
}

$user = q('SELECT * FROM users WHERE id = ? AND is_active = 1 AND pending_approval = 0', [$uid])->fetch();
if (!$user) {
    unset($_SESSION['pending_2fa']);
    redirect('login.php');
}
$hasTotp = has_totp_enabled($uid);
$hasKey = has_login_security_key($uid);
$error = null;
$useBackup = is_post() ? post('mode') === 'backup' : get('backup') === '1';

if (is_post() && post('action') === 'code') {
    require_post();
    // Wrong codes are counted against the account in the database, so starting a new session doesn't reset them.
    if (two_factor_failures($uid) >= 8) {
        $error = 'Too many wrong codes. Wait 15 minutes and try again.';
    } elseif ($useBackup ? totp_verify_backup_code($user, post('code')) : ($hasTotp && totp_verify_code($uid, post('code')) !== null)) {
        q('DELETE FROM login_attempts WHERE username = ?', ['2fa:' . $uid]);
        unset($_SESSION['pending_2fa']);
        $_SESSION['remember_device'] = !empty($pending['remember']);
        login_user($user, $pending['method'] ?? null);
        finish_login_redirect();
    } else {
        q('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', [client_ip(), '2fa:' . $uid]);
        $error = $useBackup ? "That backup code isn't right, or has already been used." : "That code isn't right.";
    }
}

render_header('Security check');
?>
<section class="auth-card card">
    <?= logo_svg(96, 'auth-logo') ?>
    <h1><?= $useBackup ? 'Enter a backup code' : 'One more step' ?></h1>
    <p class="muted">
        <?= $useBackup ? 'Enter one of the backup codes you saved when you set up your authenticator app.'
            : 'Finish signing in as ' . h($user['display_name']) . ($hasTotp ? ' with the code from your authenticator app' . ($hasKey ? ', or your security key' : '') : ' with your security key') . '.' ?>
    </p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <?php if ($hasTotp): ?>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="code"><input type="hidden" name="mode" value="<?= $useBackup ? 'backup' : 'totp' ?>">
        <label><?= $useBackup ? 'Backup code' : '6-digit code' ?>
            <input name="code" inputmode="<?= $useBackup ? 'text' : 'numeric' ?>" autocomplete="one-time-code" required autofocus></label>
        <button class="btn btn-primary btn-block" type="submit">Continue</button>
    </form>
    <p class="auth-alt"><a href="<?= h(url('login_2fa.php' . ($useBackup ? '' : '?backup=1'))) ?>">
        <?= $useBackup ? 'Use your authenticator app instead' : "Lost your phone? Use a backup code" ?></a></p>
    <?php endif; ?>

    <?php if ($hasKey): ?>
    <?php if ($hasTotp): ?><div class="divider"><span>or</span></div><?php endif; ?>
    <p class="inline-actions">
        <button class="btn <?= $hasTotp ? '' : 'btn-primary btn-block' ?>" type="button" hidden data-webauthn-login data-csrf="<?= h(csrf_token()) ?>">Use security key</button>
        <span class="small warn" data-webauthn-status></span>
    </p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
        <button class="btn-link small" type="submit">Cancel and sign in again</button>
    </form>
</section>
<?php render_footer();
