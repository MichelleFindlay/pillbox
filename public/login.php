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
        login_user($result);
        finish_login_redirect();
    }
    $error = $result;
}

render_header('Sign in');
?>
<section class="auth-card card">
    <img class="auth-logo" src="<?= h(asset('assets/logo.svg')) ?>" alt="" width="96" height="96">
    <h1>Sign in to <?= h(config('app.name')) ?></h1>
    <p class="muted">Track your medication and the health readings your doctor asks for.</p>

    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <?php if (entra_enabled()): ?>
    <a class="btn btn-microsoft btn-block" href="<?= h(url('auth_entra.php')) ?>">
        <svg viewBox="0 0 21 21" width="18" height="18" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
        <?= h(config('entra.button_label')) ?>
    </a>
    <?php endif; ?>

    <?php foreach (oidc_providers() as $key => $p): ?>
    <a class="btn btn-sso btn-block" href="<?= h(url('oidc_start.php?provider=' . urlencode($key))) ?>"><?= h($p['label']) ?></a>
    <?php endforeach; ?>

    <?php if (sso_enabled() && local_login_enabled()): ?>
    <div class="divider"><span>or use a local account</span></div>
    <?php endif; ?>

    <?php if (local_login_enabled()): ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <label>Username
            <input name="username" autocomplete="username" autocapitalize="none" required value="<?= h($username) ?>">
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
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
