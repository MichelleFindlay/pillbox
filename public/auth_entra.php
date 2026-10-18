<?php
require __DIR__ . '/../src/bootstrap.php';

if (!entra_enabled()) {
    flash('error', 'Microsoft sign-in is not configured.');
    redirect('login.php');
}
// Only from Pillbox's own sign-in buttons (a form with the CSRF token), so another site can't start a sign-in for you.
if (!is_post()) {
    redirect('login.php');
}
require_post();
unset($_SESSION['entra_link_uid']);
$_SESSION['remember_device'] = post('remember') === '1'; // "Remember this device" on the sign-in page
entra_begin_login();
