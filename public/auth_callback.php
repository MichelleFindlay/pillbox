<?php
require __DIR__ . '/../src/bootstrap.php';

if (!entra_enabled()) {
    redirect('login.php');
}

try {
    $claims = entra_handle_callback($_GET);
    $user = entra_find_or_create_user($claims);
} catch (RuntimeException $e) {
    error_log('Entra sign-in failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect('login.php');
}

login_user($user);
finish_login_redirect();
