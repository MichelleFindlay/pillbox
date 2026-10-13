<?php
/*
 * Forgotten password: enter a username or email address, and a reset link is emailed to the
 * account (see send_password_reset). The answer is the same whether or not there's an account.
 */
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect('account.php#security');
}

$sent = false;
$error = null;
if (is_post() && password_reset_enabled()) {
    require_post();
    $who = mb_strtolower(trim(post('who')));
    if ($who === '') {
        $error = 'Enter your username or email address.';
    } elseif (login_is_throttled('reset:' . mb_substr($who, 0, 90))) {
        $error = 'Too many requests. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        send_password_reset($who);
        $sent = true;
    }
}

render_header('Forgotten password');
?>
<section class="auth-card card">
    <h1>Forgotten password</h1>
    <?php if (!password_reset_enabled()): ?>
    <p>This <?= h(config('app.name')) ?> can't send emails, so passwords can't be reset here. Ask whoever runs it to reset yours: they can do it from the Admin page.</p>
    <?php elseif ($sent): ?>
    <div class="flash flash-success" role="status">If there's an account with that username or email address, and it has an email address, we've sent it a link to choose a new password.
        It works once, for <?= RESET_MINUTES ?> minutes.</div>
    <p class="muted small">Nothing arrived? Check your spam folder. If your account has no email address, or you sign in with Microsoft or another sign-in button, ask whoever runs this <?= h(config('app.name')) ?>.</p>
    <?php else: ?>
    <p class="muted">Enter your username or email address, and we'll email you a link to choose a new password.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <label>Username or email address <input name="who" required autocapitalize="none" autocomplete="username" value="<?= h(post('who')) ?>"></label>
        <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
    </form>
    <?php endif; ?>
    <p class="auth-alt"><a href="<?= h(url('login.php')) ?>">&larr; Back to sign in</a></p>
</section>
<?php render_footer();
