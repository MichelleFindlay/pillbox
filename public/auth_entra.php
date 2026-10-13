<?php
require __DIR__ . '/../src/bootstrap.php';

if (!entra_enabled()) {
    flash('error', 'Microsoft sign-in is not configured.');
    redirect('login.php');
}
unset($_SESSION['entra_link_uid']);
$_SESSION['remember_device'] = get('remember') === '1'; // "Remember this device" on the sign-in page
entra_begin_login();
