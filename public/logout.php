<?php
require __DIR__ . '/../src/bootstrap.php';

require_post();
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
logout_session();

if ($providerLogout !== null) {
    redirect($providerLogout);
}
flash('success', 'You have been signed out.');
redirect('login.php');
