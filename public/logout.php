<?php
require __DIR__ . '/../src/bootstrap.php';

require_post();
$wasEntra = ($_SESSION['provider'] ?? '') === 'entra';
logout_session();

if ($wasEntra && entra_enabled() && config('entra.single_logout')) {
    redirect(entra_logout_url());
}
flash('success', 'You have been signed out.');
redirect('login.php');
