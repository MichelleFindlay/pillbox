<?php
require __DIR__ . '/../src/bootstrap.php';

if (!entra_enabled()) {
    flash('error', 'Microsoft sign-in is not configured.');
    redirect('login.php');
}
entra_begin_login();
