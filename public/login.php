<?php
require __DIR__ . '/../src/bootstrap.php';

if (user_count() === 0) { // a new Pillbox: create the first administrator (with the setup code) first
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
        $_SESSION['remember_device'] = post('remember') === '1';
        begin_sign_in($result); // asks for the second factor first, if the account has one
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

    <?= sso_buttons() ?>

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
    <p class="muted small">First time here? <a href="<?= h(url('setup.php')) ?>">Set up this Pillbox</a>.</p>
    <?php endif; ?>
</section>
<?php render_footer();
