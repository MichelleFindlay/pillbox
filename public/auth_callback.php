<?php
require __DIR__ . '/../src/bootstrap.php';

if (!entra_enabled()) {
    redirect('login.php');
}

// Linking a Microsoft account to the signed-in user, or re-confirming it to add a new
// authenticator app or security key (both started from the Account page)?
$linkUserId = (int) ($_SESSION['entra_link_uid'] ?? 0);
unset($_SESSION['entra_link_uid']);
$linking = $linkUserId > 0 && $linkUserId === (int) (current_user()['id'] ?? 0);
$stepup = $linking && !empty($_SESSION['stepup_resume']);
unset($_SESSION['stepup_resume']);

try {
    $claims = entra_handle_callback($_GET);
    if ($linking) {
        entra_link_account($linkUserId, $claims);
        if ($stepup) {
            mark_step_up();
            flash('success', 'Confirmed. You can now continue.');
            redirect('account.php#security');
        }
        flash('success', 'Your Microsoft account is now linked. You can sign in with Microsoft or with your username and password.');
        redirect('account.php#sign-in-options');
    }
    $user = entra_find_or_create_user($claims);
} catch (RuntimeException $e) {
    error_log('Entra sign-in failed: ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect(!$linking ? 'login.php' : ($stepup ? 'account.php#security' : 'account.php#sign-in-options'));
}

login_user($user, 'entra');
finish_login_redirect();
