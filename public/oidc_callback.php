<?php
/*
 * Redirect URI for every OpenID Connect provider. Register exactly this URL
 * (e.g. https://pillbox.example.com/oidc_callback.php) with each provider.
 */
require __DIR__ . '/../src/bootstrap.php';

$linkUserId = (int) ($_SESSION['oidc']['link_uid'] ?? 0);
$linking = $linkUserId > 0 && $linkUserId === (int) (current_user()['id'] ?? 0);

try {
    $result = oidc_handle_callback($_GET);
    $p = oidc_provider($result['provider']);
    if ($linking) {
        oidc_link_account($linkUserId, $result['provider'], $result['claims']);
        flash('success', "Your {$p['name']} account is now linked. You can use it to sign in to Pillbox.");
        redirect('account.php#sign-in-options');
    }
    $user = oidc_find_or_create_user($result['provider'], $result['claims']);
} catch (RuntimeException $e) {
    error_log('OIDC sign-in failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect($linking ? 'account.php#sign-in-options' : 'login.php');
}

login_user($user, 'oidc:' . $result['provider']);
$_SESSION['oidc_id_token'] = $result['id_token']; // only used as a hint when signing out of the provider
finish_login_redirect();
