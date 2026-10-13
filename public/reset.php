<?php
/*
 * Choose a new password from an emailed reset link (reset.php?t=<selector>.<validator>). The token is
 * moved into the session straight away, so it isn't left in the address bar or browser history.
 */
require __DIR__ . '/../src/bootstrap.php';

if (get('t') !== '') {
    $_SESSION['reset_token'] = get('t');
    redirect('reset.php');
}
$user = password_reset_user((string) ($_SESSION['reset_token'] ?? ''));
if (!$user) {
    unset($_SESSION['reset_token']);
    flash('error', 'That reset link has expired or has already been used. You can ask for a new one.');
    redirect('forgot.php');
}

$error = null;
if (is_post()) {
    require_post();
    $error = validate_new_password((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
    if (!$error) {
        complete_password_reset($user, (string) $_POST['password']);
        unset($_SESSION['reset_token']);
        logout_session();
        flash('success', 'Your password has been changed. Sign in with your new password.');
        redirect('login.php');
    }
}

render_header('Choose a new password');
?>
<section class="auth-card card">
    <h1>Choose a new password</h1>
    <p class="muted">For <strong><?= h($user['username']) ?></strong>. You'll be signed out on all your devices, and need to sign in again.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="text" name="username" value="<?= h($user['username']) ?>" autocomplete="username" hidden>
        <label>New password <span class="muted small">(at least 10 characters)</span> <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block" type="submit">Change password</button>
    </form>
</section>
<?php render_footer();
