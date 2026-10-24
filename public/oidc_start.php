<?php
/*
 * Start sign-in with an OpenID Connect provider: POST oidc_start.php?provider=<key> (with the CSRF token)
 * Linking to the signed-in account: oidc_start.php?provider=<key>&link=1&csrf=<token>
 */
require __DIR__ . '/../src/bootstrap.php';

$key = get('provider');
if (!oidc_provider($key)) {
    flash('error', 'That sign-in option is not available.');
    redirect('login.php');
}

$linkUserId = null;
if (get('link') !== '1') {
    // Signing in: only from Pillbox's own sign-in buttons (a form with the CSRF token), so another site can't start one for you.
    if (!is_post()) {
        redirect('login.php');
    }
    require_post();
    $_SESSION['remember_device'] = post('remember') === '1'; // "Remember this device" on the sign-in page
}
if (get('link') === '1') {
    $user = require_login();
    if (!hash_equals(csrf_token(), get('csrf'))) {
        http_response_code(400);
        exit('Your session expired. Go back, reload the page and try again.');
    }
    $linkUserId = (int) $user['id'];
}

try {
    oidc_begin($key, $linkUserId);
} catch (RuntimeException $e) {
    log_line('OIDC start failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect($linkUserId ? 'account.php#sign-in-options' : 'login.php');
}
