<?php
/*
 * Create an account: with any single sign-on provider, or a username and password.
 * Only while sign-ups are open (config 'signup'); new accounts may wait for an admin.
 */
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
if (!signups_open()) {
    flash('error', 'New accounts can only be added by an administrator.');
    redirect('login.php');
}
if (user_count() === 0 && !sso_enabled()) {
    redirect('setup.php');
}

$error = null;
$form = ['display_name' => '', 'username' => '', 'email' => ''];
if (is_post() && local_login_enabled()) {
    require_post();
    $form = ['display_name' => trim(post('display_name')), 'username' => mb_strtolower(trim(post('username'))), 'email' => mb_strtolower(trim(post('email')))];
    if (post('website') !== '') {
        // Filled in a field people can't see: a bot. Look the same as success, create nothing.
        flash('success', 'Thanks. Your account is waiting for an administrator to approve it.');
        redirect('login.php');
    }
    if (post('agree') !== '1') {
        $error = 'Please agree to the terms of use and privacy policy.';
    }
    $result = $error ? $error : create_local_signup($form['display_name'], $form['username'], $form['email'],
        (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? ''));
    if (is_array($result)) {
        login_user($result); // stops here (back to sign-in) if it's waiting for approval
        flash('success', 'Welcome to ' . config('app.name') . '! A few questions to set it up for you.');
        redirect('welcome.php');
    }
    $error = $result;
}

render_header('Create an account');
?>
<section class="auth-card card">
    <?= logo_svg(96, 'auth-logo') ?>
    <h1>Create your <?= h(config('app.name')) ?> account</h1>
    <p class="muted"><?= signup_needs_approval()
        ? 'An administrator approves new accounts before they can be used.'
        : 'Keep track of your medicines, health readings and appointments.' ?></p>

    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>

    <?php if (sso_enabled()): ?>
    <p class="muted small">By signing up with <?= entra_enabled() && oidc_providers() ? 'a provider' : (entra_enabled() ? 'Microsoft' : 'a provider') ?>, you agree to the
        <a href="<?= h(url('terms.php')) ?>" target="_blank" rel="noopener">terms of use</a> and <a href="<?= h(url('privacy.php')) ?>" target="_blank" rel="noopener">privacy policy</a>.</p>
    <?php endif; ?>
    <?php if (entra_enabled()): ?>
    <a class="btn btn-microsoft btn-block" href="<?= h(url('auth_entra.php')) ?>">
        <svg viewBox="0 0 21 21" width="18" height="18" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
        Sign up with Microsoft
    </a>
    <?php endif; ?>
    <?php foreach (oidc_providers() as $key => $p): ?>
    <a class="btn btn-sso btn-block" href="<?= h(url('oidc_start.php?provider=' . urlencode($key))) ?>">Sign up with <?= h($p['name']) ?></a>
    <?php endforeach; ?>

    <?php if (sso_enabled() && local_login_enabled()): ?>
    <div class="divider"><span>or choose a username and password</span></div>
    <?php endif; ?>

    <?php if (local_login_enabled()): ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <label>Your name <input name="display_name" required maxlength="150" autocomplete="name" value="<?= h($form['display_name']) ?>"></label>
        <label>Username <input name="username" required minlength="3" maxlength="100" autocapitalize="none" autocomplete="username" value="<?= h($form['username']) ?>"></label>
        <label><span>Email <span class="muted small">(optional<?= signup_needs_approval() && mail_enabled() ? ': we will tell you when your account is approved' : '' ?>)</span></span>
            <input type="email" name="email" maxlength="255" autocomplete="email" value="<?= h($form['email']) ?>"></label>
        <label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password"></label>
        <label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
        <label class="check"><input type="checkbox" name="agree" value="1" required<?= checked(post('agree') === '1') ?>>
            <span>I agree to the <a href="<?= h(url('terms.php')) ?>" target="_blank" rel="noopener">terms of use</a> and
                <a href="<?= h(url('privacy.php')) ?>" target="_blank" rel="noopener">privacy policy</a>, including the use of the health information I add</span></label>
        <button class="btn btn-primary btn-block" type="submit">Create account</button>
    </form>
    <?php endif; ?>

    <p class="muted small">Already have an account? <a href="<?= h(url('login.php')) ?>">Sign in</a>.
        <?= sso_enabled() ? 'To add another way to sign in to an existing account, sign in and use Account settings.' : '' ?></p>
</section>
<?php render_footer();
