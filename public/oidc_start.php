<?php
/*
 * Start sign-in with an OpenID Connect provider: oidc_start.php?provider=<key>
 * Linking to the signed-in account: oidc_start.php?provider=<key>&link=1&csrf=<token>
 */
require __DIR__ . '/../src/bootstrap.php';

$key = get('provider');
if (!oidc_provider($key)) {
    flash('error', 'That sign-in option is not available.');
    redirect('login.php');
}

$linkUserId = null;
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
    error_log('OIDC start failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect($linkUserId ? 'account.php#sign-in-options' : 'login.php');
}
