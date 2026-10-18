<?php
/*
 * Unlock Mind with its PIN. A forgotten PIN can be removed straight after signing in
 * again (which needs the account password or single sign-on).
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$return = safe_return(get('return') ?: post('return'), 'mind.php');
// Only straight after a real sign-in (or re-confirming who you are): not a remembered device signing itself back in.
$freshLogin = step_up_confirmed() && ($_SESSION['provider'] ?? '') !== 'remembered';
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
    if (pin_is_throttled('#mind' . $uid)) {
        $error = 'Too many wrong PINs. Please wait a while, or sign out and in again to remove the PIN.';
    } elseif (password_verify(post('pin'), $user['mind_pin_hash'])) {
        q('DELETE FROM login_attempts WHERE username = ?', ['#mind' . $uid]);
        $_SESSION['mind_unlocked_at'] = time();
        redirect($return);
    } else {
        pin_failed('#mind' . $uid);
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
        <button class="btn" type="button" hidden data-webauthn-unlock data-csrf="<?= h(csrf_token()) ?>" data-return="<?= h($return) ?>">Unlock with a passkey</button>
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
