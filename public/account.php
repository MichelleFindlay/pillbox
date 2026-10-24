<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$isLocal = $user['auth_provider'] === 'local';
$hasPassword = $user['password_hash'] !== null;
$errors = [];

if (is_post()) {
    require_post();
    if (in_array(post('action'), ['profile', 'password', 'sign_out_others', 'link_entra', 'unlink_entra', 'unlink_oidc', 'email_prefs', 'test_email', 'remove_security_key',
        'totp_start', 'totp_confirm', 'totp_cancel_setup', 'totp_remove', 'totp_rename', 'totp_regenerate_codes', 'rename_security_key', 'stepup_entra', 'stepup_oidc'], true)) {
        demo_blocked('Changing sign-in details and email');
    }
    switch (post('action')) {
        case 'profile':
            $name = post('display_name');
            $email = mb_strtolower(post('email'));
            $tz = post('timezone');
            $username = $isLocal ? mb_strtolower(post('username')) : $user['username'];
            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = 'Enter your name (up to 150 characters).';
            }
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255)) {
                $errors[] = 'Enter a valid email address, or leave it empty.';
            }
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose a valid timezone.';
            }
            if ($isLocal && $username !== $user['username'] && ($e = validate_username($username))) {
                $errors[] = $e;
            }
            // The email address is where password reset links go: changing it needs the current password
            // (or a recent confirmation), so a session left open can't be used to take the account over.
            $emailChanged = $email !== mb_strtolower((string) $user['email']);
            if (!$errors && $emailChanged && $hasPassword && !step_up_confirmed() && !verify_step_up_password($user, (string) ($_POST['current'] ?? ''))) {
                $errors[] = "To change your email address, enter your current password (or wait 15 minutes if you've tried too many times).";
            }
            if (!$errors) {
                if ($emailChanged && $user['email'] && mail_enabled()) {
                    $app = (string) config('app.name');
                    send_mail((string) $user['email'], "Your $app email address was changed",
                        "Hello {$user['display_name']},\n\nThe email address on your $app account was just changed" . ($email ? " to $email" : ' (removed)') . ".\n\nIf this wasn't you, contact whoever runs this $app straight away.\n");
                }
                q('UPDATE users SET display_name = ?, email = ?, timezone = ?, username = ?, pronouns = ?, gender_identity = ? WHERE id = ?',
                    [$name, $email ?: null, $tz, $username, trim(mb_substr(post('pronouns'), 0, 40)) ?: null, trim(mb_substr(post('gender_identity'), 0, 100)) ?: null, $uid]);
                flash('success', 'Profile saved.' . ($isLocal && $username !== $user['username'] ? " Sign in as \"$username\" from now on." : ''));
                redirect('account.php');
            }
            break;

        case 'health_profile':
            $dob = post('date_of_birth') === '' ? null : parse_date(post('date_of_birth'));
            if (post('date_of_birth') !== '' && (!$dob || $dob > local_today() || $dob < new DateTimeImmutable('1900-01-01'))) {
                $errors[] = 'Enter a valid date of birth, or leave it empty.';
            }
            $sex = isset(SEXES[post('sex')]) ? post('sex') : null;
            $programmes = $sex === 'other' ? implode(',', array_intersect(array_keys(SCREENING_PROGRAMMES), (array) ($_POST['screening'] ?? []))) : null;
            $areas = array_values(array_intersect(array_keys(care_categories()), (array) ($_POST['care_areas'] ?? [])));
            // Body inventory: organ => state (and surgery date), or NULL when not used.
            $inventory = null;
            if (post('inventory_on') === '1') {
                $inventory = [];
                foreach (BODY_PARTS as $part => $_) {
                    $state = $_POST['inv'][$part]['state'] ?? '';
                    if (isset(BODY_STATES[$state])) {
                        $date = parse_date((string) ($_POST['inv'][$part]['date'] ?? ''));
                        $inventory[$part] = ['state' => $state, 'date' => $state === 'removed' && $date ? $date->format('Y-m-d') : null];
                    }
                }
            }
            $inventoryJson = $inventory === null ? null : json_encode($inventory);
            if (!$errors) {
                q('UPDATE users SET date_of_birth = ?, sex = ?, screening_programmes = ?, care_areas = ?, mind_enabled = ?, body_inventory = ? WHERE id = ?',
                    [$dob?->format('Y-m-d'), $sex, $programmes, implode(',', $areas), post('mind_enabled') === '1' ? 1 : 0, $inventoryJson, $uid]);
                if (($sex || $inventory !== null) && ($sex !== $user['sex'] || $programmes !== $user['screening_programmes'] || $inventoryJson !== $user['body_inventory'])) {
                    apply_screening_profile(['sex' => $sex, 'screening_programmes' => $programmes, 'body_inventory' => $inventoryJson] + $user);
                }
                flash('success', 'Health profile saved.' . (!$areas ? ' All Care categories are switched off, so the Care tab is empty.' : ''));
                redirect('account.php#health-profile');
            }
            break;

        case 'push_prefs':
            q('UPDATE users SET push_doses = ?, push_care = ?, push_appointments = ?, push_private = ? WHERE id = ?',
                [post('push_doses') === '1' ? 1 : 0, post('push_care') === '1' ? 1 : 0, post('push_appointments') === '1' ? 1 : 0, post('push_private') === '1' ? 1 : 0, $uid]);
            flash('success', 'Notification settings saved.');
            redirect('account.php#notifications');
        case 'push_remove':
            q('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            flash('success', 'That device will no longer get notifications.');
            redirect('account.php#notifications');
        case 'email_prefs':
            q('UPDATE users SET email_digest = ?, email_digest_hour = ?, email_appointments = ? WHERE id = ?',
                [post('email_digest') === '1' ? 1 : 0, min(23, max(0, (int) post('email_digest_hour'))), post('email_appointments') === '1' ? 1 : 0, $uid]);
            flash('success', 'Email settings saved.');
            redirect('account.php#email');
        case 'test_email':
            $error = $user['email'] ? send_mail($user['email'], 'Pillbox test email',
                "This is a test email from Pillbox. If you can read this, email is working.\n",
                mail_html('Test email', '<p>If you can read this, email from Pillbox is working.</p>')) : 'Add your email address to your profile first.';
            flash($error ? 'error' : 'success', $error ? "The test email couldn't be sent: $error" : 'Test email sent to ' . $user['email'] . '. Check your spam folder if it doesn\'t arrive.');
            redirect('account.php#email');
        case 'import':
            $file = $_FILES['backup'] ?? null;
            if (post('confirm_text') !== 'REPLACE') {
                flash('error', 'Type REPLACE to confirm: importing replaces everything in your Pillbox.');
            } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                flash('error', $file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'That file is too big for this server to accept.' : 'Choose the backup file to import.');
            } elseif ($file['size'] > BACKUP_MAX_UPLOAD) {
                flash('error', 'That backup file is too big (over ' . (int) (BACKUP_MAX_UPLOAD / 1024 / 1024) . ' MB).');
            } else {
                $backup = backup_from_file(json_decode((string) file_get_contents($file['tmp_name']), true));
                if (is_string($backup)) {
                    flash('error', $backup);
                } else {
                    try {
                        $counts = import_backup($user, $backup);
                        flash('success', 'Imported: ' . (int) ($counts['medications'] ?? 0) . ' medications, ' . (int) ($counts['dose_logs'] ?? 0) . ' dose records, '
                            . (int) ($counts['health_readings'] ?? 0) . ' readings, ' . (int) ($counts['appointments'] ?? 0) . ' appointments and the rest of your data.');
                    } catch (Throwable $e) {
                        error_log('Pillbox import failed: ' . $e);
                        flash('error', "The import didn't work, so nothing was changed. The file may be damaged or from an incompatible version.");
                    }
                }
            }
            redirect('account.php#data');
        case 'accessibility':
            q('UPDATE users SET text_size = ?, wide_spacing = ?, easy_font = ?, reduce_motion = ?, hide_streaks = ?, dose_followup = ? WHERE id = ?', [
                in_array(post('text_size'), ['l', 'xl'], true) ? post('text_size') : null, post('wide_spacing') === '1' ? 1 : 0, post('easy_font') === '1' ? 1 : 0,
                post('reduce_motion') === '1' ? 1 : 0, post('hide_streaks') === '1' ? 1 : 0, in_array((int) post('dose_followup'), [0, 15, 30, 60], true) ? (int) post('dose_followup') : 0, $uid,
            ]);
            flash('success', 'Saved.');
            redirect('account.php#accessibility');

        case 'privacy':
            q('UPDATE users SET privacy_blur = ?, disguise = ? WHERE id = ?',
                [post('privacy_blur') === '1' ? 1 : 0, isset(DISGUISES[post('disguise')]) ? post('disguise') : null, $uid]);
            flash('success', 'Privacy settings saved.' . (post('disguise') !== (string) $user['disguise'] ? ' To change the home screen icon, remove Pillbox from your home screen and add it again.' : ''));
            redirect('account.php#privacy');

        case 'appearance':
            $theme = post('theme');
            $mode = post('color_mode');
            if (isset(THEMES[$theme], COLOR_MODES[$mode])) {
                q('UPDATE users SET theme = ?, color_mode = ? WHERE id = ?', [$theme, $mode, $uid]);
                flash('success', 'Appearance saved.');
            }
            redirect('account.php#appearance');

        case 'password':
            // Change your password, or (Microsoft-only accounts) add one so you can sign in either way.
            $new = (string) ($_POST['new'] ?? '');
            if ($hasPassword && !verify_step_up_password($user, (string) ($_POST['current'] ?? ''))) {
                $errors[] = "Your current password is incorrect (or you've tried too many times: wait 15 minutes).";
            } elseif (!$hasPassword && !step_up_confirmed()) {
                // Adding a password to a single sign-on account: confirm it's you with that sign-in first.
                $errors[] = 'Confirm it\'s you first, with the button below.';
            } elseif ($e = validate_new_password($new, (string) ($_POST['confirm'] ?? ''))) {
                $errors[] = $e;
            }
            $username = $user['username'];
            if (!$hasPassword) {
                $username = mb_strtolower(post('username'));
                if (!local_login_enabled()) {
                    $errors[] = 'Password sign-in is turned off on this Pillbox.';
                } elseif ($e = validate_username($username)) {
                    $errors[] = $e;
                }
            }
            if (!$errors) {
                q("UPDATE users SET password_hash = ?, username = ?, auth_provider = 'local' WHERE id = ?",
                    [password_hash($new, PASSWORD_DEFAULT), $username, $uid]);
                bump_session_version($uid, true);
                flash('success', $hasPassword
                    ? 'Password changed. Any other devices have been signed out.'
                    : "Password added. You can now sign in as \"$username\" with your password, or with Microsoft.");
                redirect('account.php#security');
            }
            break;

        case 'forget_device':
            $forget = (int) post('device_id');
            q('DELETE FROM remembered_devices WHERE id = ? AND user_id = ?', [$forget, $uid]);
            if ($forget === (int) ($_SESSION['device_id'] ?? 0)) {
                remember_cookie('', time() - 3600);
            }
            flash('success', 'That device will need to sign in again next time.');
            redirect('account.php#security');

        case 'sign_out_others':
            bump_session_version($uid, true);
            flash('success', 'You have been signed out on all other devices.');
            redirect('account.php#security');

        case 'forget_session':
            q('DELETE FROM user_sessions WHERE id = ? AND user_id = ? AND id <> ?', [(int) post('id'), $uid, (int) ($_SESSION['session_row_id'] ?? 0)]);
            flash('success', 'That session has been signed out.');
            redirect('account.php#security');

        case 'remove_security_key':
            q("DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ? AND purpose = 'login'", [(int) post('id'), $uid]);
            flash('success', 'Security key removed.');
            redirect('account.php#security');

        case 'rename_security_key':
            if ($label = trim(mb_substr((string) post('label'), 0, 100))) {
                q("UPDATE webauthn_credentials SET label = ? WHERE id = ? AND user_id = ? AND purpose = 'login'", [$label, (int) post('id'), $uid]);
            }
            redirect('account.php#security');

        case 'totp_start':
            if (!can_add_login_2fa($user)) {
                break;
            }
            if (!step_up_confirmed() && !verify_step_up_password($user, (string) ($_POST['current'] ?? ''))) {
                flash('error', "That's not your current password, or you've tried too many times. Wait 15 minutes and try again.");
                redirect('account.php#security');
            }
            // Only one setup in progress at a time: drop any other unconfirmed one first.
            q('DELETE FROM totp_credentials WHERE user_id = ? AND confirmed_at IS NULL', [$uid]);
            q('INSERT INTO totp_credentials (user_id, secret, label) VALUES (?, ?, ?)', [$uid, seal(totp_generate_secret()), 'Authenticator app']);
            redirect('account.php#security');

        case 'totp_confirm':
            $pendingCred = totp_pending_credential($uid, (int) post('id'));
            if (!$pendingCred) {
                flash('error', 'Start setting up an authenticator app first.');
            } elseif (!totp_code_matches($pendingCred['secret'], post('code'))) {
                flash('error', "That code isn't right. Check the time on your phone is correct, and try again.");
            } else {
                $label = trim(mb_substr((string) post('label'), 0, 100)) ?: 'Authenticator app';
                $hadAny = has_totp_enabled($uid);
                q('UPDATE totp_credentials SET confirmed_at = UTC_TIMESTAMP(), label = ? WHERE id = ?', [$label, $pendingCred['id']]);
                bump_session_version($uid, true);
                if ($hadAny) {
                    flash('success', 'Authenticator app added. Any other devices have been signed out.');
                } else {
                    $codes = totp_generate_backup_codes();
                    q('UPDATE users SET totp_backup_codes = ? WHERE id = ?', [totp_hash_backup_codes($codes), $uid]);
                    $_SESSION['totp_backup_codes_shown'] = $codes;
                    flash('success', 'Authenticator app added. Save your backup codes somewhere safe &mdash; any other devices have been signed out.');
                }
            }
            redirect('account.php#security');

        case 'totp_cancel_setup':
            q('DELETE FROM totp_credentials WHERE id = ? AND user_id = ? AND confirmed_at IS NULL', [(int) post('id'), $uid]);
            redirect('account.php#security');

        case 'totp_rename':
            if ($label = trim(mb_substr((string) post('label'), 0, 100))) {
                q('UPDATE totp_credentials SET label = ? WHERE id = ? AND user_id = ? AND confirmed_at IS NOT NULL', [$label, (int) post('id'), $uid]);
            }
            redirect('account.php#security');

        case 'totp_remove':
            q('DELETE FROM totp_credentials WHERE id = ? AND user_id = ? AND confirmed_at IS NOT NULL', [(int) post('id'), $uid]);
            if (has_totp_enabled($uid)) {
                flash('success', 'Authenticator app removed.');
            } else {
                // No authenticator app left: the backup codes have nothing left to back up.
                q('UPDATE users SET totp_backup_codes = NULL WHERE id = ?', [$uid]);
                flash('success', "Authenticator app removed. Your backup codes no longer work either, since there's nothing left for them to back up.");
            }
            redirect('account.php#security');

        case 'totp_regenerate_codes':
            if (has_totp_enabled($uid)) {
                $codes = totp_generate_backup_codes();
                q('UPDATE users SET totp_backup_codes = ? WHERE id = ?', [totp_hash_backup_codes($codes), $uid]);
                $_SESSION['totp_backup_codes_shown'] = $codes;
                flash('success', 'New backup codes generated. Your old codes no longer work.');
            }
            redirect('account.php#security');

        case 'metrics':
            $builtin = builtin_metrics();
            $chosen = array_values(array_filter((array) ($_POST['metrics'] ?? []), fn ($k) => is_string($k) && isset($builtin[$k])));
            q('UPDATE users SET enabled_metrics = ? WHERE id = ?', [implode(',', $chosen), $uid]);
            flash('success', 'Tracked measurements updated.');
            redirect('account.php#metrics');

        case 'add_custom':
            $label = post('label');
            $unit = post('unit');
            if ($label === '' || mb_strlen($label) > 80 || mb_strlen($unit) > 30) {
                $errors[] = 'Give the measurement a name (up to 80 characters) and an optional unit (up to 30).';
                break;
            }
            q('INSERT INTO custom_metrics (user_id, label, unit) VALUES (?, ?, ?)', [$uid, $label, $unit ?: null]);
            flash('success', "\"$label\" added. You'll find it on the Readings page.");
            redirect('account.php#metrics');

        case 'delete_custom':
            $cid = (int) post('id');
            if (q('SELECT 1 FROM custom_metrics WHERE id = ? AND user_id = ?', [$cid, $uid])->fetch()) {
                transaction(function () use ($cid, $uid) {
                    q('DELETE FROM health_readings WHERE user_id = ? AND metric = ?', [$uid, 'c' . $cid]);
                    q('DELETE FROM custom_metrics WHERE id = ?', [$cid]);
                });
                flash('success', 'Custom measurement and its readings deleted.');
            }
            redirect('account.php#metrics');

        case 'link_entra':
            if (entra_enabled() && $user['auth_provider'] !== 'entra') {
                $_SESSION['entra_link_uid'] = $uid;
                entra_begin_login();
            }
            break;

        case 'stepup_entra':
            if (entra_enabled() && $user['entra_oid']) {
                $_SESSION['entra_link_uid'] = $uid;
                $_SESSION['stepup_resume'] = true;
                entra_begin_login();
            }
            break;

        case 'stepup_oidc':
            $key = post('provider');
            if (oidc_provider($key) && isset(user_identities($uid)[$key])) {
                $_SESSION['stepup_resume'] = true;
                oidc_begin($key, $uid);
            }
            break;

        case 'unlink_entra':
            // Never remove someone's last way of signing in.
            if ($user['auth_provider'] !== 'entra' && $user['entra_oid'] !== null && sign_in_method_count($user) > 1) {
                q('UPDATE users SET entra_oid = NULL, entra_tid = NULL WHERE id = ?', [$uid]);
                flash('success', 'Your Microsoft account has been unlinked.');
            }
            redirect('account.php#sign-in-options');

        case 'unlink_oidc':
            $provider = post('provider');
            $identities = user_identities($uid);
            if (isset($identities[$provider]) && sign_in_method_count($user) > 1) {
                q('DELETE FROM user_identities WHERE user_id = ? AND provider = ?', [$uid, $provider]);
                flash('success', (oidc_provider($provider)['name'] ?? $provider) . ' account unlinked.');
            }
            redirect('account.php#sign-in-options');

        case 'delete_account':
            if (is_last_admin($user)) {
                $errors[] = "You're the only administrator. Make someone else an admin before deleting your account.";
            } elseif ($hasPassword && !password_verify((string) ($_POST['confirm_password'] ?? ''), (string) $user['password_hash'])) {
                $errors[] = 'Your password is incorrect, so your account was not deleted.';
            } elseif (!$hasPassword && post('confirm_text') !== 'DELETE') {
                $errors[] = 'Type DELETE in capitals to confirm, or your account will not be deleted.';
            }
            if (!$errors) {
                q('DELETE FROM users WHERE id = ?', [$uid]);
                logout_session();
                flash('success', 'Your account and all of your data have been deleted.');
                redirect('login.php');
            }
            break;
    }
}

$enabled = $user['enabled_metrics'] !== null ? explode(',', $user['enabled_metrics']) : DEFAULT_METRICS;
$custom = q('SELECT * FROM custom_metrics WHERE user_id = ? ORDER BY label', [$uid])->fetchAll();
$counts = q(
    'SELECT (SELECT COUNT(*) FROM medications WHERE user_id = ?) AS meds,
            (SELECT COUNT(*) FROM dose_logs WHERE user_id = ?) AS doses,
            (SELECT COUNT(*) FROM health_readings WHERE user_id = ?) AS readings',
    [$uid, $uid, $uid]
)->fetch();
$posted = is_post() ? $_POST : [];
$identities = user_identities($uid);
$methods = [];
if ($hasPassword && local_login_enabled()) {
    $methods[] = 'your username and password';
}
if ($user['entra_oid'] && entra_enabled()) {
    $methods[] = 'Microsoft';
}
foreach ($identities as $key => $identity) {
    if ($identity['subject'] !== null && ($p = oidc_provider($key))) {
        $methods[] = $p['name'];
    }
}

render_header('Account', 'account');
?>
<div class="page-head"><h1>Your account <?= help_link('account', '', 'your account') ?></h1></div>
<?= form_errors($errors) ?>

<nav class="chips" aria-label="Account sections">
    <a class="chip" href="#profile">Profile</a>
    <a class="chip" href="#health-profile">Health profile</a>
    <a class="chip" href="#appearance">Appearance</a>
    <a class="chip" href="#security">Password &amp; security</a>
    <?php if (sso_enabled() || $user['entra_oid'] || $identities): ?><a class="chip" href="#sign-in-options">Sign-in options</a><?php endif; ?>
    <a class="chip" href="#metrics">Measurements</a>
    <a class="chip" href="#notifications">Notifications</a>
    <a class="chip" href="#email">Email</a>
    <a class="chip" href="#install">Phone app</a>
    <a class="chip" href="#data">Your data</a>
</nav>

<section class="card" id="profile">
    <h2>Profile</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <div class="grid-2">
            <label>Name <input name="display_name" required maxlength="150" autocomplete="name" value="<?= h($posted['display_name'] ?? $user['display_name']) ?>"></label>
            <label>Email <span class="muted small">(optional)</span>
                <input type="email" name="email" maxlength="255" autocomplete="email" value="<?= h($posted['email'] ?? $user['email']) ?>"></label>
            <?php if ($hasPassword && !step_up_confirmed()): ?>
            <label>Current password <span class="muted small">(only needed to change your email)</span>
                <input type="password" name="current" autocomplete="current-password"></label>
            <?php endif; ?>
            <?php if ($isLocal): ?>
            <label>Username <span class="muted small">(what you sign in with)</span>
                <input name="username" required autocapitalize="none" autocomplete="username" value="<?= h($posted['username'] ?? $user['username']) ?>"></label>
            <?php endif; ?>
            <label>Timezone
                <select name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= selected($tz === ($posted['timezone'] ?? $user['timezone'])) ?>><?= h($tz) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span>Pronouns <span class="muted small">(optional, shown on your reports)</span></span>
                <input name="pronouns" maxlength="40" list="pronoun-list" value="<?= h($posted['pronouns'] ?? (string) $user['pronouns']) ?>" placeholder="e.g. she/her, he/him, they/them">
                <datalist id="pronoun-list"><option value="she/her"><option value="he/him"><option value="they/them"><option value="she/they"><option value="he/they"></datalist></label>
            <label><span>Gender identity <span class="muted small">(optional, in your own words)</span></span>
                <input name="gender_identity" maxlength="100" value="<?= h($posted['gender_identity'] ?? (string) $user['gender_identity']) ?>"></label>
        </div>
        <button class="btn btn-primary" type="submit">Save profile</button>
    </form>
</section>

<section class="card" id="health-profile">
    <div class="card-head">
        <h2>Health profile <?= help_link('account', 'health-profile', 'health profile') ?></h2>
        <a class="btn btn-small" href="<?= h(url('welcome.php')) ?>">Run the setup again</a>
    </div>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="health_profile">
        <div class="grid-2">
            <label><span>Date of birth <span class="muted small">(for screening dates)</span></span>
                <input type="date" name="date_of_birth" max="<?= h(local_today()->format('Y-m-d')) ?>" value="<?= h($user['date_of_birth'] ?? '') ?>"></label>
        </div>
        <fieldset>
            <legend>Sex on your GP record <span class="muted small">(optional: NHS screening invitations are sent by this)</span></legend>
            <div class="repeat-choice">
                <?php foreach (SEXES + ['' => 'Not set'] as $key => $label): ?>
                <label class="check"><input type="radio" name="sex" value="<?= h($key) ?>" id="sex-<?= h($key ?: 'none') ?>"<?= checked((string) $user['sex'] === $key) ?>> <?= h($label) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="screening-choice" data-show-when="sex-other">
                <p class="muted small">Tick the screening you need:</p>
                <div class="checks">
                    <?php $chosen = explode(',', (string) $user['screening_programmes']); foreach (SCREENING_PROGRAMMES as $key => $p): ?>
                    <label class="check"><input type="checkbox" name="screening[]" value="<?= h($key) ?>"<?= checked(in_array($key, $chosen, true)) ?>>
                        <span><?= h($p['label']) ?> <span class="muted small">(<?= h($p['who']) ?>)</span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </fieldset>
        <?php $inv = body_inventory($user); ?>
        <fieldset>
            <legend>Body inventory <span class="muted small">(optional and private)</span></legend>
            <label class="check"><input type="checkbox" name="inventory_on" value="1" id="inv-on"<?= checked($inv !== null) ?>>
                <span>Use my body, not a gender, for screening reminders and which pages appear (e.g. Pregnancy needs a uterus)</span></label>
            <div class="inventory" data-show-when="inv-on">
                <?php foreach (BODY_PARTS as $part => $label): $state = $inv[$part]['state'] ?? ''; ?>
                <div class="inventory-row">
                    <span><?= h($label) ?></span>
                    <select name="inv[<?= $part ?>][state]" aria-label="<?= h($label) ?>"><option value="">&ndash;</option>
                        <?php foreach (BODY_STATES as $k => $l): ?><option value="<?= $k ?>"<?= selected($state === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select>
                    <label><span class="sr">Surgery date</span><input type="date" name="inv[<?= $part ?>][date]" value="<?= h((string) ($inv[$part]['date'] ?? '')) ?>" title="Date of surgery, if removed"></label>
                </div>
                <?php endforeach; ?>
                <p class="muted small">Cervical screening follows a cervix, breast screening breast or chest tissue, and AAA screening a prostate or testes (now or before surgery).
                    Bowel screening is for everyone from 50 to 74. You can change any of this at any time.</p>
            </div>
        </fieldset>
        <fieldset>
            <legend>Care categories</legend>
            <p class="muted small">Only the ones you switch on appear in the Care tab, on Today and in your calendar feed. Switching one off keeps its history.</p>
            <div class="checks">
                <?php $myAreas = user_care_areas($user); foreach (care_categories_for($user) as $key => $area): ?>
                <label class="check"><input type="checkbox" name="care_areas[]" value="<?= h($key) ?>"<?= checked(in_array($key, $myAreas, true)) ?>> <?= $area['icon'] ?> <?= h($area['title']) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <fieldset>
            <legend>Mind</legend>
            <label class="check"><input type="checkbox" name="mind_enabled" value="1"<?= checked((bool) $user['mind_enabled']) ?>>
                <span>Show the Mind tab <span class="muted small">(mood check-ins, coping toolkit, safety plan, journal; and the Help button with crisis contacts)</span></span></label>
        </fieldset>
        <button class="btn btn-primary" type="submit">Save health profile</button>
    </form>
</section>

<section class="card" id="accessibility">
    <h2>Display and reminders <?= help_link('accessibility', '', 'display and accessibility') ?></h2>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="accessibility">
        <div class="grid-2">
            <label>Text size <select name="text_size"><?php foreach (['' => 'Normal', 'l' => 'Larger', 'xl' => 'Largest'] as $k => $l): ?><option value="<?= $k ?>"<?= selected((string) $user['text_size'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label><span>"Did you take it?" follow-up <span class="muted small">(notifications)</span></span>
                <select name="dose_followup"><?php foreach ([0 => 'Off', 15 => '15 minutes after a dose reminder', 30 => '30 minutes after', 60 => '1 hour after'] as $k => $l): ?><option value="<?= $k ?>"<?= selected((int) $user['dose_followup'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="check"><input type="checkbox" name="wide_spacing" value="1"<?= checked((bool) $user['wide_spacing']) ?>> <span>More space between lines and letters</span></label>
        <label class="check"><input type="checkbox" name="easy_font" value="1"<?= checked((bool) $user['easy_font']) ?>> <span>Easy-read font <span class="muted small">(a rounder, more open font, often easier with dyslexia)</span></span></label>
        <label class="check"><input type="checkbox" name="reduce_motion" value="1"<?= checked((bool) $user['reduce_motion']) ?>> <span>Turn off animations</span></label>
        <label class="check"><input type="checkbox" name="hide_streaks" value="1"<?= checked((bool) $user['hide_streaks']) ?>> <span>Hide streaks <span class="muted small">(no "days in a row" counts)</span></span></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
</section>

<section class="card" id="privacy">
    <h2>Privacy <?= help_link('privacy', 'account-privacy', 'privacy') ?></h2>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="privacy">
        <label class="check"><input type="checkbox" name="privacy_blur" value="1"<?= checked((bool) $user['privacy_blur']) ?>>
            <span>Hide the screen when I switch to another app <span class="muted small">(so it doesn't show in the app switcher)</span></span></label>
        <fieldset><legend>Name and icon on my home screen</legend>
            <div class="repeat-choice">
                <label class="check"><input type="radio" name="disguise" value=""<?= checked(!$user['disguise']) ?>> <?= h(config('app.name')) ?></label>
                <?php foreach (DISGUISES as $k => $d): ?>
                <label class="check"><input type="radio" name="disguise" value="<?= $k ?>"<?= checked($user['disguise'] === $k) ?>>
                    <img src="<?= h(asset('assets/icons/' . $d['icon'] . '-192.png')) ?>" alt="" width="24" height="24" class="disguise-icon"> <?= h($d['name']) ?></label>
                <?php endforeach; ?>
            </div>
            <p class="muted small">Also used for the browser tab. To change an app that's already on your home screen, remove it and add it again.</p>
        </fieldset>
        <p class="muted small">For neutral reminders, tick <strong>Private</strong> on a medicine, or turn on "Hide details on the lock screen" under Notifications.</p>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
</section>

<section class="card" id="appearance">
    <h2>Appearance</h2>
    <?php [$currentTheme, $currentMode] = user_theme($user); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="appearance">
        <fieldset>
            <legend>Colour theme</legend>
            <div class="theme-grid">
                <?php foreach (THEMES as $key => $t): ?>
                <label class="theme-choice">
                    <input type="radio" name="theme" value="<?= h($key) ?>"<?= checked($key === $currentTheme) ?>>
                    <span class="theme-card" data-palette="<?= h($key) ?>" aria-hidden="true">
                        <span class="tc-bar"><?= logo_svg(20) ?> Pillbox</span>
                        <span class="tc-body"><span class="tc-btn">Take</span><span class="tc-link">Link</span></span>
                    </span>
                    <span class="tc-name"><?= h($t['label']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <fieldset>
            <legend>Light or dark</legend>
            <div class="mode-choices">
                <?php foreach (COLOR_MODES as $key => $label): ?>
                <label class="check"><input type="radio" name="color_mode" value="<?= h($key) ?>"<?= checked($key === $currentMode) ?>> <?= h($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button class="btn btn-primary" type="submit">Save appearance</button>
    </form>
</section>

<section class="card" id="security">
    <h2>Password &amp; security</h2>
    <p class="muted small">
        You can sign in with <?= h(implode(' or ', $methods) ?: 'no method that is currently switched on') ?>.
        <?= $user['last_login_at'] ? 'Last sign-in: ' . h(local_time($user['last_login_at'])) . '.' : '' ?>
    </p>

    <?php if ($hasPassword && !local_login_enabled()): ?>
    <p class="muted small">Password sign-in is switched off on this Pillbox &mdash; please sign in with Microsoft.</p>
    <?php elseif ($hasPassword): ?>
    <h3>Change password</h3>
    <form method="post" class="stack narrow">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
        <label>New password <span class="muted small">(at least 10 characters)</span> <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Change password</button>
    </form>
    <?php elseif (local_login_enabled()): ?>
    <h3>Add a password</h3>
    <p class="muted small">Optional. Lets you sign in without Microsoft too, for example if your work account is unavailable.</p>
    <?php $addPwStepUp = step_up_method($user); if (!step_up_confirmed() && in_array($addPwStepUp['type'], ['entra', 'oidc'], true)): ?>
    <p class="muted small">Confirm it's you first:</p>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="stepup_<?= $addPwStepUp['type'] ?>">
        <?php if ($addPwStepUp['provider']): ?><input type="hidden" name="provider" value="<?= h($addPwStepUp['provider']) ?>"><?php endif; ?>
        <button class="btn btn-small" type="submit">Confirm with <?= h($addPwStepUp['label']) ?></button>
    </form>
    <?php else: ?>
    <form method="post" class="stack narrow">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Choose a username <input name="username" required autocapitalize="none" autocomplete="username" value="<?= h($posted['username'] ?? '') ?>"></label>
        <label>Password <span class="muted small">(at least 10 characters)</span> <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Add password</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (can_add_login_2fa($user)): ?>
    <h3>Authenticator apps</h3>
    <p class="muted small">Add one or more authenticator apps (Google Authenticator, Microsoft Authenticator, 1Password, &hellip;) and you're asked for a code from any of them after your password when you sign in. Add a spare (for example on a second phone) so losing one doesn't lock you out.</p>
    <?php if ($codes = $_SESSION['totp_backup_codes_shown'] ?? null): unset($_SESSION['totp_backup_codes_shown']);
        $codesFile = (string) config('app.name', 'Pillbox') . " backup codes for " . $user['display_name'] . "\n"
            . "Generated " . gmdate('j F Y') . ". Each code works once, if you lose access to your authenticator apps.\n\n" . implode("\n", $codes) . "\n"; ?>
    <div class="flash flash-success">
        <p>Save these backup codes somewhere safe &mdash; each works once, if you lose access to all your authenticator apps. They won't be shown again.</p>
        <ul class="plain grid-2 backup-codes"><?php foreach ($codes as $c): ?><li><code><?= h($c) ?></code></li><?php endforeach; ?></ul>
        <p><a class="btn btn-small" download="<?= h(strtolower(str_replace(' ', '-', (string) config('app.name', 'Pillbox')))) ?>-backup-codes.txt"
            href="data:text/plain;charset=utf-8,<?= rawurlencode($codesFile) ?>">Download backup codes</a></p>
    </div>
    <?php endif; ?>

    <?php $totpApps = totp_credentials($uid); ?>
    <?php if ($totpApps): ?>
    <ul class="plain">
        <?php foreach ($totpApps as $t): ?>
        <li class="logline"><span><strong><?= h($t['label']) ?></strong>
            <br><span class="muted small">Added <?= h(local_time($t['created_at'], 'j M Y')) ?><?= $t['last_used_at'] ? ' · last used ' . h(local_time($t['last_used_at'], 'j M Y, H:i')) : '' ?></span></span>
            <span class="inline-actions">
                <details class="q-edit"><summary class="btn btn-small">Rename</summary>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="totp_rename"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <input name="label" value="<?= h($t['label']) ?>" maxlength="100" required><button class="btn btn-small" type="submit">Save</button></form>
                </details>
                <form method="post" data-confirm="Remove this authenticator app? You'll no longer be able to sign in with codes from it."><?= csrf_field() ?><input type="hidden" name="action" value="totp_remove"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button class="btn btn-small" type="submit">Remove</button></form>
            </span></li>
        <?php endforeach; ?>
    </ul>
    <p class="inline-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="totp_regenerate_codes">
            <button class="btn btn-small" type="submit">Generate new backup codes</button></form>
    </p>
    <?php endif; ?>

    <?php $pendingTotp = totp_pending_credential($uid); ?>
    <?php if ($pendingTotp): ?>
    <p class="muted small">Scan this with your authenticator app, then enter the 6-digit code it shows to confirm.</p>
    <?= totp_qr_svg(totp_provisioning_uri($pendingTotp['secret'], (string) ($user['username'] ?: $user['email']), (string) config('app.name', 'Pillbox'))) ?>
    <p class="muted small">Can't scan it? Enter this key instead: <code><?= h(trim(chunk_split($pendingTotp['secret'], 4, ' '))) ?></code></p>
    <form method="post" class="stack narrow">
        <?= csrf_field() ?><input type="hidden" name="action" value="totp_confirm"><input type="hidden" name="id" value="<?= (int) $pendingTotp['id'] ?>">
        <label>Nickname <span class="muted small">(optional, e.g. "Phone")</span> <input name="label" maxlength="100" placeholder="Authenticator app"></label>
        <label>6-digit code <input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></label>
        <button class="btn btn-primary" type="submit">Confirm</button>
    </form>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="totp_cancel_setup"><input type="hidden" name="id" value="<?= (int) $pendingTotp['id'] ?>">
        <button class="btn-link small" type="submit">Cancel</button>
    </form>
    <?php else: $stepUpMethod = step_up_method($user); ?>
    <?php if (!step_up_confirmed() && in_array($stepUpMethod['type'], ['entra', 'oidc'], true)): ?>
    <p class="muted small">Confirm it's you to <?= $totpApps ? 'add another authenticator app' : 'set up an authenticator app' ?>.</p>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="stepup_<?= $stepUpMethod['type'] ?>">
        <?php if ($stepUpMethod['provider']): ?><input type="hidden" name="provider" value="<?= h($stepUpMethod['provider']) ?>"><?php endif; ?>
        <button class="btn btn-small" type="submit">Confirm with <?= h($stepUpMethod['label']) ?></button>
    </form>
    <?php else: ?>
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="totp_start">
        <?php if (!step_up_confirmed()): ?><label>Current password <input type="password" name="current" autocomplete="current-password" required></label><?php endif; ?>
        <button class="btn btn-small" type="submit"><?= $totpApps ? 'Add another authenticator app' : 'Set up an authenticator app' ?></button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <?php $keys = q("SELECT * FROM webauthn_credentials WHERE user_id = ? AND purpose = 'login' ORDER BY id", [$uid])->fetchAll(); ?>
    <h3>Security keys</h3>
    <p class="muted small">Add a security key (e.g. a YubiKey) and it's asked for after your password when you sign in, as well as your username and password.</p>
    <?php if ($keys): ?>
    <ul class="plain">
        <?php foreach ($keys as $k): ?>
        <li class="logline"><span><strong><?= h($k['label']) ?></strong>
            <br><span class="muted small">Added <?= h(local_time($k['created_at'], 'j M Y')) ?><?= $k['last_used_at'] ? ' · last used ' . h(local_time($k['last_used_at'], 'j M Y, H:i')) : '' ?></span></span>
            <span class="inline-actions">
                <details class="q-edit"><summary class="btn btn-small">Rename</summary>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="rename_security_key"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                        <input name="label" value="<?= h($k['label']) ?>" maxlength="100" required><button class="btn btn-small" type="submit">Save</button></form>
                </details>
                <form method="post" data-confirm="Remove this security key? You'll be able to sign in with just your password again."><?= csrf_field() ?><input type="hidden" name="action" value="remove_security_key"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                    <button class="btn btn-small" type="submit">Remove</button></form>
            </span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php $stepUpMethod = step_up_method($user); ?>
    <?php if (!step_up_confirmed() && in_array($stepUpMethod['type'], ['entra', 'oidc'], true)): ?>
    <p class="muted small">Confirm it's you to add a security key.</p>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="stepup_<?= $stepUpMethod['type'] ?>">
        <?php if ($stepUpMethod['provider']): ?><input type="hidden" name="provider" value="<?= h($stepUpMethod['provider']) ?>"><?php endif; ?>
        <button class="btn btn-small" type="submit">Confirm with <?= h($stepUpMethod['label']) ?></button>
    </form>
    <?php else: ?>
    <p class="inline-actions">
        <label class="sr" for="security-key-label">Nickname for this key</label>
        <input id="security-key-label" type="text" hidden data-webauthn-label placeholder="Nickname (e.g. YubiKey 5C)" maxlength="100" autocomplete="off">
        <?php if (!step_up_confirmed()): ?>
        <label class="sr" for="security-key-password">Current password</label>
        <input id="security-key-password" type="password" hidden data-webauthn-password placeholder="Current password" autocomplete="current-password">
        <?php endif; ?>
        <button class="btn btn-small" type="button" hidden data-webauthn-register-key data-purpose="login" data-csrf="<?= h(csrf_token()) ?>" data-label="Security key">Add a security key</button>
        <span class="small warn" data-webauthn-status></span>
    </p>
    <?php endif; ?>
    <?php endif; ?>

    <?php $devices = q('SELECT * FROM remembered_devices WHERE user_id = ? AND expires_at > UTC_TIMESTAMP() ORDER BY last_used_at DESC', [$uid])->fetchAll(); ?>
    <?php if ($devices || remember_days() > 0): ?>
    <h3>Remembered devices</h3>
    <?php if (!$devices): ?><p class="muted small">None. Tick <strong>Remember this device</strong> when you sign in on a phone or computer you use often, and it stays signed in for <?= remember_days() ?> days after you last use it.</p>
    <?php else: ?>
    <p class="muted small">These stay signed in until <?= remember_days() ?> days after they were last used. Mind and private pages still ask for their PIN.</p>
    <ul class="plain">
        <?php foreach ($devices as $d): $here = (int) $d['id'] === (int) ($_SESSION['device_id'] ?? 0); ?>
        <li class="logline"><span><strong><?= h((string) ($d['label'] ?: 'A device')) ?></strong><?= $here ? ' <span class="badge">This device</span>' : '' ?>
            <br><span class="muted small">Remembered <?= h(local_time($d['created_at'], 'j M Y')) ?> · last used <?= h(local_time($d['last_used_at'], 'j M Y, H:i')) ?></span></span>
            <form method="post" data-confirm="Forget this device? It will need to sign in again."><?= csrf_field() ?><input type="hidden" name="action" value="forget_device"><input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
                <button class="btn btn-small" type="submit">Forget</button></form></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php endif; ?>

    <?php $sessions = q('SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_active_at DESC', [$uid])->fetchAll(); ?>
    <h3>Active sessions</h3>
    <p class="muted small">Every device signed in right now. Different from <strong>Remembered devices</strong> above: a session here is open at this moment; a remembered device can sign itself back in later without a password.</p>
    <?php if (!$sessions): ?>
    <p class="muted small">None found.</p>
    <?php else: ?>
    <ul class="plain">
        <?php foreach ($sessions as $s): $here = (int) $s['id'] === (int) ($_SESSION['session_row_id'] ?? 0); ?>
        <li class="logline"><span><strong><?= h($s['label']) ?></strong><?= $here ? ' <span class="badge">This device</span>' : '' ?>
            <br><span class="muted small">Signed in <?= h(local_time($s['created_at'], 'j M Y')) ?> · active <?= h(local_time($s['last_active_at'], 'j M Y, H:i')) ?></span>
            <?php if ($s['ip']): ?><br><span class="muted small"><?= h($s['ip']) ?><?= $s['location'] ? ' · ' . h($s['location']) : '' ?></span><?php endif; ?></span>
            <?php if (!$here): ?>
            <form method="post" data-confirm="Sign out this session?"><?= csrf_field() ?><input type="hidden" name="action" value="forget_session"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-small" type="submit">Sign out</button></form>
            <?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3>Other devices</h3>
    <p class="muted small">Lost a phone, or signed in on a shared computer? Sign out everywhere except here, and forget every other remembered device.</p>
    <form method="post" data-confirm="Sign out of Pillbox on every other device?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sign_out_others">
        <button class="btn" type="submit">Sign out everywhere else</button>
    </form>
</section>

<?php if (sso_enabled() || $user['entra_oid'] || $identities): ?>
<section class="card" id="sign-in-options">
    <h2>Sign-in options</h2>
    <p class="muted small">Link other accounts so you can sign in with them too. Your medications and readings stay exactly as they are.</p>
    <?php $canUnlink = sign_in_method_count($user) > 1; ?>
    <ul class="signin-options">
        <?php if (entra_enabled() || $user['entra_oid']): ?>
        <li>
            <span><strong>Microsoft</strong>
                <?php if ($user['auth_provider'] === 'entra'): ?><span class="muted small">&mdash; your main sign-in</span>
                <?php elseif ($user['entra_oid']): ?><span class="muted small">&#10003; linked</span><?php endif; ?></span>
            <?php if ($user['auth_provider'] !== 'entra' && $user['entra_oid'] && $canUnlink): ?>
            <form method="post" data-confirm="Unlink your Microsoft account?">
                <?= csrf_field() ?><input type="hidden" name="action" value="unlink_entra">
                <button class="btn btn-small" type="submit">Unlink</button>
            </form>
            <?php elseif ($user['auth_provider'] !== 'entra' && !$user['entra_oid'] && entra_enabled()): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="link_entra">
                <button class="btn btn-small btn-primary" type="submit">Link</button>
            </form>
            <?php endif; ?>
        </li>
        <?php endif; ?>
        <?php foreach (oidc_providers() + array_fill_keys(array_keys($identities), null) as $key => $p):
            $p ??= oidc_provider($key);
            $identity = $identities[$key] ?? null;
            $linked = $identity && $identity['subject'] !== null; ?>
        <li>
            <span><strong><?= h($p['name'] ?? $key) ?></strong>
                <?php if ($linked): ?><span class="muted small">&#10003; linked<?= $identity['email'] ? ' as ' . h($identity['email']) : '' ?></span><?php endif; ?>
                <?php if (!$p): ?><span class="muted small">(switched off)</span><?php endif; ?></span>
            <?php if ($identity && $canUnlink): ?>
            <form method="post" data-confirm="Unlink your <?= h($p['name'] ?? $key) ?> account?">
                <?= csrf_field() ?><input type="hidden" name="action" value="unlink_oidc"><input type="hidden" name="provider" value="<?= h($key) ?>">
                <button class="btn btn-small" type="submit">Unlink</button>
            </form>
            <?php elseif (!$linked && $p): ?>
            <a class="btn btn-small btn-primary" href="<?= h(url('oidc_start.php?' . http_build_query(['provider' => $key, 'link' => 1, 'csrf' => csrf_token()]))) ?>">Link</a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$canUnlink && ($user['entra_oid'] || $identities)): ?><p class="muted small">You can't unlink your only way of signing in. Add a password or link another account first.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card" id="metrics">
    <h2>Measurements to track</h2>
    <p class="muted small">Switch on the readings your doctor, nurse or pharmacist has asked you to keep.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="metrics">
        <div class="checks">
            <?php foreach (builtin_metrics() as $key => $def): ?>
            <label class="check"><input type="checkbox" name="metrics[]" value="<?= h($key) ?>"<?= checked(in_array($key, $enabled, true)) ?>> <?= h($def['label']) ?></label>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit">Save</button>
    </form>

    <h3>Your own measurements</h3>
    <p class="muted small">Need to track something else? Add it here &mdash; for example "Ankle swelling (cm)" or "Seizures (count)".</p>
    <?php if ($custom): ?>
    <ul class="plain">
        <?php foreach ($custom as $c): ?>
        <li class="logline">
            <span><?= h($c['label']) ?> <?= $c['unit'] ? '<span class="muted">(' . h($c['unit']) . ')</span>' : '' ?></span>
            <form method="post" data-confirm="Delete &quot;<?= h($c['label']) ?>&quot; and all of its readings?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_custom">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn-link danger" type="submit">Delete</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_custom">
        <label>Name <input name="label" required maxlength="80"></label>
        <label>Unit <input name="unit" maxlength="30" placeholder="optional"></label>
        <button class="btn" type="submit">Add measurement</button>
    </form>
</section>

<section class="card" id="notifications">
    <h2>Notifications <?= help_link('account', 'notifications-and-email', 'notifications') ?></h2>
    <?php if (!push_supported()): ?>
    <p class="muted">This server can't send notifications (PHP's OpenSSL or curl extension is missing).</p>
    <?php elseif ($user['is_demo']): ?>
    <p class="muted">Notifications are switched off in the demo.</p>
    <?php else: $devices = q('SELECT * FROM push_subscriptions WHERE user_id = ? ORDER BY id', [$uid])->fetchAll(); ?>
    <p class="muted small">Reminders on your phone or computer, even when Pillbox isn't open. Turn them on in each browser or device you use.</p>
    <div data-push data-csrf="<?= h(csrf_token()) ?>">
        <p class="small" data-push-status>Checking this device&hellip;</p>
        <span class="inline-actions">
            <button class="btn btn-primary btn-small" type="button" hidden data-push-on>Turn on notifications on this device</button>
            <button class="btn btn-small" type="button" hidden data-push-test>Send a test</button>
            <button class="btn btn-small" type="button" hidden data-push-off>Turn off on this device</button>
        </span>
    </div>
    <?php if ($devices): ?>
    <details class="q-edit"><summary>Devices with notifications on (<?= count($devices) ?>)</summary>
        <ul class="plain"><?php foreach ($devices as $d): ?>
            <li class="logline"><span><?= h($d['label']) ?> <span class="muted small">since <?= h(local_time($d['created_at'], 'j M Y')) ?><?= $d['last_used_at'] ? ', last sent ' . h(local_time($d['last_used_at'], 'j M H:i')) : '' ?></span></span>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="push_remove"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn-link danger small" type="submit">Remove</button></form></li>
        <?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="push_prefs">
        <label class="check"><input type="checkbox" name="push_doses" value="1"<?= checked((bool) $user['push_doses']) ?>> <span>Dose reminders <span class="muted small">(at dose time, if it isn't logged yet)</span></span></label>
        <label class="check"><input type="checkbox" name="push_care" value="1"<?= checked((bool) $user['push_care']) ?>> <span>Care reminders <span class="muted small">(tasks marked "Remind me", and a daily note of what's coming up)</span></span></label>
        <label class="check"><input type="checkbox" name="push_appointments" value="1"<?= checked((bool) $user['push_appointments']) ?>> <span>Appointment reminders</span></label>
        <label class="check"><input type="checkbox" name="push_private" value="1"<?= checked((bool) $user['push_private']) ?>> <span>Hide details on the lock screen <span class="muted small">(just "Pillbox: you have a reminder")</span></span></label>
        <span class="inline-actions"><button class="btn" type="submit">Save</button></span>
    </form>
    <p class="muted small">The Mind check-in reminder (set in Mind) only ever says "Pillbox check-in". Reminders arrive when the scheduled tasks run, so ask your administrator to run them every 5 minutes.</p>
    <?php endif; ?>
</section>

<section class="card" id="email">
    <h2>Email</h2>
    <?php if (!mail_enabled()): ?>
    <p class="muted">Email isn't set up on this Pillbox yet.<?= $user['is_admin'] ? ' Fill in the mail settings in config/config.php, and set up the scheduled tasks (see the Admin page).' : ' Ask your administrator.' ?></p>
    <?php elseif (!$user['email']): ?>
    <p class="muted">Add your email address to your <a href="#profile">profile</a> to get emails.</p>
    <?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="email_prefs">
        <label class="check"><input type="checkbox" name="email_digest" value="1"<?= checked((bool) $user['email_digest']) ?>>
            <span>Daily summary <span class="muted small">(today's doses, anything missed, medicines running low or expiring, Care items coming up, appointments; nothing from Mind)</span></span></label>
        <label class="narrow">Send it at
            <select name="email_digest_hour"><?php for ($hr = 5; $hr <= 22; $hr++): ?><option value="<?= $hr ?>"<?= selected((int) $user['email_digest_hour'] === $hr) ?>><?= sprintf('%02d:00', $hr) ?></option><?php endfor; ?></select></label>
        <label class="check"><input type="checkbox" name="email_appointments" value="1"<?= checked((bool) $user['email_appointments']) ?>>
            <span>Appointment reminders <span class="muted small">(at each appointment's reminder time, or the day before; with your questions to ask)</span></span></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
    <?php if (mail_enabled() && $user['email']): ?>
    <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="test_email">
        <button class="btn btn-small" type="submit">Send me a test email</button></form>
    <?php endif; ?>
</section>

<section class="card" id="install">
    <h2>Install Pillbox on your phone</h2>
    <p class="installed-note" hidden>&#10003; You're using the installed Pillbox app.</p>
    <div class="install-help">
        <p>Pillbox can be added to your home screen and opens like any other app: full screen, with its own icon.</p>
        <p class="install-android" hidden><button class="btn btn-primary" type="button" id="install-button">Install the app</button></p>
        <h3>iPhone or iPad</h3>
        <ol>
            <li>Open this site in <strong>Safari</strong>.</li>
            <li>Tap the <strong>Share</strong> button (the square with an arrow pointing up).</li>
            <li>Scroll down and tap <strong>Add to Home Screen</strong>, then <strong>Add</strong>.</li>
        </ol>
        <h3>Android</h3>
        <ol>
            <li>Open this site in <strong>Chrome</strong>.</li>
            <li>Tap the <strong>&#8942;</strong> menu, then <strong>Add to Home screen</strong> or <strong>Install app</strong>.</li>
        </ol>
    </div>
</section>

<section class="card" id="data">
    <h2>Your data <?= help_link('privacy', 'your-data', 'your data') ?></h2>
    <p>Pillbox holds <?= (int) $counts['meds'] ?> medication<?= (int) $counts['meds'] === 1 ? '' : 's' ?>,
        <?= (int) $counts['doses'] ?> dose record<?= (int) $counts['doses'] === 1 ? '' : 's' ?> and
        <?= (int) $counts['readings'] ?> health reading<?= (int) $counts['readings'] === 1 ? '' : 's' ?> for you.</p>
    <p><a class="btn" href="<?= h(url('account_export.php')) ?>">Download all my data</a></p>
    <p class="muted small">A single file (JSON) with everything: medications, doses, readings, appointments, Care, Mind and your settings. Keep it safe: it's
        your health record. For a summary to show a doctor, use the <a href="<?= h(url('report.php')) ?>">Report</a> page instead.</p>

    <h3>Import data</h3>
    <p class="muted small">Restore a file from "Download all my data", for example to move to another Pillbox. It <strong>replaces</strong> everything in your
        Pillbox with what's in the file. Your sign-in, sharing, passkeys and calendar or sync links aren't changed.</p>
    <form method="post" enctype="multipart/form-data" class="stack narrow" data-confirm="Replace everything in your Pillbox with this file?">
        <?= csrf_field() ?><input type="hidden" name="action" value="import">
        <label>Backup file <input type="file" name="backup" accept=".json,application/json" required></label>
        <label>Type REPLACE to confirm <input name="confirm_text" required autocomplete="off" autocapitalize="characters" pattern="REPLACE"></label>
        <span class="inline-actions"><button class="btn" type="submit">Import</button></span>
    </form>

    <h3 class="danger">Delete my account</h3>
    <?php if (is_last_admin($user)): ?>
        <p class="muted small">You're the only administrator, so you can't delete your account until someone else is made an admin.</p>
    <?php else: ?>
    <p class="muted small">Permanently deletes your account and <strong>all</strong> of your medications, dose history and readings. This can't be undone &mdash; download your data first if you might want it.</p>
    <form method="post" class="stack narrow" data-confirm="Permanently delete your Pillbox account and all of your data?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_account">
        <?php if ($hasPassword): ?>
        <label>Enter your password to confirm <input type="password" name="confirm_password" required autocomplete="current-password"></label>
        <?php else: ?>
        <label>Type DELETE to confirm <input name="confirm_text" required autocomplete="off" autocapitalize="characters" pattern="DELETE"></label>
        <?php endif; ?>
        <button class="btn btn-danger" type="submit">Delete my account</button>
    </form>
    <?php endif; ?>
</section>
<?php render_footer();
