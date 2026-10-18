<?php
require __DIR__ . '/../src/bootstrap.php';

if (user_count() === 0 && !sso_enabled()) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('index.php');
}

$error = null;
$username = '';
if (is_post() && post('action') === 'demo' && demo_enabled()) {
    require_post();
    login_user(create_demo_account(), 'demo');
    redirect('index.php');
}
if (is_post() && local_login_enabled()) {
    require_post();
    $username = post('username');
    $result = attempt_local_login($username, (string) ($_POST['password'] ?? ''));
    if (is_array($result)) {
        if (has_totp_enabled((int) $result['id']) || has_login_security_key((int) $result['id'])) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = ['uid' => (int) $result['id'], 'at' => time(), 'remember' => post('remember') === '1'];
            redirect('login_2fa.php');
        }
        $_SESSION['remember_device'] = post('remember') === '1';
        login_user($result);
        finish_login_redirect();
    }
    $error = $result;
}

render_header('Sign in');
?>
<section class="auth-card card">
    <?= logo_svg(96, 'auth-logo') ?>
    <h1>Sign in to <?= h(config('app.name')) ?></h1>
    <p class="muted">Track your medication and the health readings your doctor asks for.</p>

    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <?php // Remember this device: above Sign in, or above the sign-in buttons if there's no password sign-in. It applies to both.
    $rememberBox = remember_days() > 0 ? '<label class="check remember-device"><input type="checkbox" name="remember" value="1" data-remember-device> <span>Remember this device for '
        . remember_days() . ' days<br><span class="muted small">Not on a shared or public computer</span></span></label>' : ''; ?>
    <?php if (!local_login_enabled() && (entra_enabled() || oidc_providers())): ?><?= $rememberBox ?><?php endif; ?>

    <?php if (entra_enabled()): ?>
    <a class="btn btn-microsoft btn-block" data-sso href="<?= h(url('auth_entra.php')) ?>">
        <svg viewBox="0 0 21 21" width="18" height="18" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
        <?= h(config('entra.button_label')) ?>
    </a>
    <?php endif; ?>

    <?php foreach (oidc_providers() as $key => $p): ?>
    <a class="btn btn-sso btn-block" data-sso href="<?= h(url('oidc_start.php?provider=' . urlencode($key))) ?>"><?= h($p['label']) ?></a>
    <?php endforeach; ?>

    <?php if (sso_enabled() && local_login_enabled()): ?>
    <div class="divider"><span>or use a local account</span></div>
    <?php endif; ?>

    <?php if (local_login_enabled()): ?>
    <form method="post" class="stack" id="local-login">
        <?= csrf_field() ?>
        <label>Username
            <input name="username" autocomplete="username" autocapitalize="none" required value="<?= h($username) ?>">
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <?= $rememberBox ?>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
    <p class="auth-alt"><a href="<?= h(url('forgot.php')) ?>">Forgotten your password?</a></p>
    <?php endif; ?>

    <?php if (signups_open() && user_count() > 0): ?>
    <p class="auth-alt">New here? <a href="<?= h(url('register.php')) ?>">Create an account</a></p>
    <?php endif; ?>

    <?php if (demo_enabled()): ?>
    <div class="divider"><span>or</span></div>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="demo">
        <button class="btn btn-block" type="submit">Try the demo</button>
    </form>
    <p class="muted small">Look around with sample data for a made-up person. Nothing you change is kept.</p>
    <?php endif; ?>

    <?php if (user_count() === 0 && local_login_enabled()): ?>
    <p class="muted small">First time here? <a href="<?= h(url('setup.php')) ?>">Create a local administrator account</a><?= sso_enabled() ? ', or use a sign-in button above &mdash; the first person to sign in that way becomes the administrator' : '' ?>.</p>
    <?php endif; ?>
</section>
<?php render_footer();
