<?php
require __DIR__ . '/../src/bootstrap.php';

if (!entra_enabled()) {
    redirect('login.php');
}

// Linking a Microsoft account to the signed-in user (started from the Account page)?
$linkUserId = (int) ($_SESSION['entra_link_uid'] ?? 0);
unset($_SESSION['entra_link_uid']);
$linking = $linkUserId > 0 && $linkUserId === (int) (current_user()['id'] ?? 0);

try {
    $claims = entra_handle_callback($_GET);
    if ($linking) {
        entra_link_account($linkUserId, $claims);
        flash('success', 'Your Microsoft account is now linked. You can sign in with Microsoft or with your username and password.');
        redirect('account.php#microsoft');
    }
    $user = entra_find_or_create_user($claims);
} catch (RuntimeException $e) {
    error_log('Entra sign-in failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect($linking ? 'account.php#microsoft' : 'login.php');
}

login_user($user, 'entra');
finish_login_redirect();
