<?php
/*
 * Unlock Mind with its PIN. A forgotten PIN can be removed straight after signing in
 * again (which needs the account password or single sign-on).
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$return = safe_return(get('return') ?: post('return'), 'mind.php');
$freshLogin = ($_SESSION['login_at'] ?? 0) > time() - 300;
$error = null;

if (!$user['mind_pin_hash'] || mind_unlocked($user)) {
    redirect($return);
}

if (is_post()) {
    require_post();
    if (post('action') === 'reset' && $freshLogin) {
        q('UPDATE users SET mind_pin_hash = NULL WHERE id = ?', [$uid]);
        flash('success', 'PIN removed. You can set a new one under Privacy on the Mind page.');
        redirect('mind.php#privacy');
    }
    $tries = $_SESSION['mind_pin_tries'] ?? ['n' => 0, 'at' => 0];
    if ($tries['n'] >= 5 && $tries['at'] > time() - 300) {
        $error = 'Too many wrong PINs. Wait 5 minutes and try again.';
    } elseif (password_verify(post('pin'), $user['mind_pin_hash'])) {
        unset($_SESSION['mind_pin_tries']);
        $_SESSION['mind_unlocked_at'] = time();
        redirect($return);
    } else {
        $_SESSION['mind_pin_tries'] = ['n' => ($tries['at'] > time() - 300 ? $tries['n'] : 0) + 1, 'at' => time()];
        $error = "That PIN isn't right.";
    }
}

render_header('Mind locked', 'mind');
?>
<section class="auth-card card">
    <h1>Mind is locked</h1>
    <p class="muted">Enter your PIN to open it.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= h($return) ?>">
        <label>PIN <input type="password" name="pin" inputmode="numeric" autocomplete="off" required autofocus></label>
        <button class="btn btn-primary" type="submit">Unlock</button>
    </form>
    <?php if (q('SELECT 1 FROM webauthn_credentials WHERE user_id = ? LIMIT 1', [$uid])->fetch()): ?>
    <p class="inline-actions">
        <button class="btn" type="button" hidden data-webauthn-unlock data-csrf="<?= h(csrf_token()) ?>" data-return="<?= h($return) ?>">Use fingerprint or face</button>
        <span class="small warn" data-webauthn-status></span>
    </p>
    <?php endif; ?>
    <?php if ($freshLogin): ?>
    <form method="post" class="inline-actions" data-confirm="Remove the PIN from Mind? Your entries are kept.">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset">
        <button class="btn-link small" type="submit">Forgot your PIN? Remove it</button>
    </form>
    <?php else: ?>
    <p class="muted small">Forgot your PIN? Sign out and sign in again, then come back here to remove it.</p>
    <?php endif; ?>
    <p><a class="btn btn-help" href="<?= h(url('help.php')) ?>">Need help now?</a></p>
</section>
<?php render_footer();
