<?php
require __DIR__ . '/../src/bootstrap.php';

require_post();
$demoId = real_user()['is_demo'] ?? false ? (int) real_user()['id'] : null;
$provider = (string) ($_SESSION['provider'] ?? '');
// Work out the provider's sign-out URL before the session (and its ID token hint) is cleared.
$providerLogout = null;
if ($provider === 'entra' && entra_enabled() && config('entra.single_logout')) {
    $providerLogout = entra_logout_url();
} elseif (str_starts_with($provider, 'oidc:')) {
    try {
        $providerLogout = oidc_logout_url(substr($provider, 5));
    } catch (RuntimeException $e) {
        error_log('OIDC sign-out URL failed: ' . $e->getMessage());
    }
}
forget_this_device();
logout_session();
if ($demoId) {
    q('DELETE FROM users WHERE id = ? AND is_demo = 1', [$demoId]); // everything else goes with it
    flash('success', 'Thanks for trying Pillbox. Your demo has been cleared.');
    redirect('login.php');
}

if ($providerLogout !== null) {
    redirect($providerLogout);
}
flash('success', 'You have been signed out.');
redirect('login.php');
