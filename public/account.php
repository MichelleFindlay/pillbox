<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$isLocal = $user['auth_provider'] === 'local';
$hasPassword = $user['password_hash'] !== null;
$errors = [];

if (is_post()) {
    require_post();
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
            if (!$errors) {
                q('UPDATE users SET display_name = ?, email = ?, timezone = ?, username = ? WHERE id = ?',
                    [$name, $email ?: null, $tz, $username, $uid]);
                flash('success', 'Profile saved.' . ($isLocal && $username !== $user['username'] ? " Sign in as \"$username\" from now on." : ''));
                redirect('account.php');
            }
            break;

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
            if ($hasPassword && !password_verify((string) ($_POST['current'] ?? ''), (string) $user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
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

        case 'sign_out_others':
            bump_session_version($uid, true);
            flash('success', 'You have been signed out on all other devices.');
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
<div class="page-head"><h1>Your account</h1></div>
<?= form_errors($errors) ?>

<nav class="chips" aria-label="Account sections">
    <a class="chip" href="#profile">Profile</a>
    <a class="chip" href="#appearance">Appearance</a>
    <a class="chip" href="#security">Password &amp; security</a>
    <?php if (sso_enabled() || $user['entra_oid'] || $identities): ?><a class="chip" href="#sign-in-options">Sign-in options</a><?php endif; ?>
    <a class="chip" href="#metrics">Measurements</a>
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
            <?php if ($isLocal): ?>
            <label>Username <span class="muted small">(what you sign in with)</span>
                <input name="username" required autocapitalize="none" autocomplete="username" value="<?= h($posted['username'] ?? $user['username']) ?>"></label>
            <?php endif; ?>
            <label>Timezone
                <select name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= selected($tz === ($posted['timezone'] ?? $user['timezone'])) ?>><?= h($tz) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <button class="btn btn-primary" type="submit">Save profile</button>
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
                        <span class="tc-bar"><img src="<?= h(asset('assets/logo.svg')) ?>" alt="" width="20" height="20"> Pillbox</span>
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
    <form method="post" class="stack narrow">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Choose a username <input name="username" required autocapitalize="none" autocomplete="username" value="<?= h($posted['username'] ?? '') ?>"></label>
        <label>Password <span class="muted small">(at least 10 characters)</span> <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Add password</button>
    </form>
    <?php endif; ?>

    <h3>Other devices</h3>
    <p class="muted small">Lost a phone, or signed in on a shared computer? Sign out everywhere except here.</p>
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
    <h2>Your data</h2>
    <p>Pillbox holds <?= (int) $counts['meds'] ?> medication<?= (int) $counts['meds'] === 1 ? '' : 's' ?>,
        <?= (int) $counts['doses'] ?> dose record<?= (int) $counts['doses'] === 1 ? '' : 's' ?> and
        <?= (int) $counts['readings'] ?> health reading<?= (int) $counts['readings'] === 1 ? '' : 's' ?> for you.</p>
    <p><a class="btn" href="<?= h(url('account_export.php')) ?>">Download all my data</a></p>
    <p class="muted small">A single file (JSON) with your profile, medications, dose schedules, dose history and readings. For a summary to show a doctor, use the <a href="<?= h(url('report.php')) ?>">Report</a> page instead.</p>

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
