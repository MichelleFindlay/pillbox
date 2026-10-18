<?php
/*
 * User administration: create local users, pre-register Microsoft users,
 * reset passwords, grant admin, disable or delete accounts.
 */
require __DIR__ . '/../src/bootstrap.php';

$admin = require_admin();
$errors = [];
$new = ['provider' => 'local', 'username' => '', 'email' => '', 'display_name' => ''];

if (is_post()) {
    require_post();
    $action = post('action');

    if ($action === 'create') {
        // 'local', 'entra', or 'oidc:<provider key>'
        $chosen = post('provider');
        $oidcKey = str_starts_with($chosen, 'oidc:') && oidc_provider(substr($chosen, 5)) ? substr($chosen, 5) : null;
        $new = ['provider' => $oidcKey ? $chosen : ($chosen === 'entra' ? 'entra' : 'local'), 'username' => mb_strtolower(post('username')),
            'email' => mb_strtolower(post('email')), 'display_name' => post('display_name')];
        if ($new['display_name'] === '' || mb_strlen($new['display_name']) > 150) {
            $errors[] = 'Enter a name.';
        }
        if ($new['email'] !== '' && !filter_var($new['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($new['provider'] === 'local') {
            if ($e = validate_username($new['username'])) {
                $errors[] = $e;
            }
            if ($e = validate_new_password((string) ($_POST['password'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                $errors[] = $e;
            }
        } else {
            $providerName = $oidcKey ? oidc_provider($oidcKey)['name'] : 'Microsoft';
            if ($new['email'] === '') {
                $errors[] = "$providerName users are matched by their sign-in email address, so enter it.";
            } elseif (q('SELECT 1 FROM users WHERE email = ?', [$new['email']])->fetch()) {
                $errors[] = 'A user with that email address already exists.';
            }
        }
        if (!$errors) {
            transaction(function () use ($new, $oidcKey) {
                q(
                    'INSERT INTO users (username, email, display_name, password_hash, auth_provider, is_admin, timezone) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $new['provider'] === 'local' ? $new['username'] : null,
                        $new['email'] ?: null,
                        $new['display_name'],
                        $new['provider'] === 'local' ? password_hash((string) $_POST['password'], PASSWORD_DEFAULT) : null,
                        $oidcKey ? 'oidc' : $new['provider'],
                        isset($_POST['is_admin']) ? 1 : 0,
                        config('app.default_timezone'),
                    ]
                );
                if ($oidcKey) {
                    // Linked on their first sign-in, when the provider confirms this (verified) email.
                    q('INSERT INTO user_identities (user_id, provider, email) VALUES (?, ?, ?)', [db()->lastInsertId(), $oidcKey, $new['email']]);
                }
            });
            flash('success', $new['display_name'] . ' added.');
            redirect('admin.php');
        }
    } elseif ($action === 'update_install' || $action === 'update_restore') {
        @set_time_limit(300);
        try {
            flash('success', $action === 'update_install' ? updater_install((array) latest_version(true)) : updater_restore(post('backup')));
        } catch (Throwable $e) {
            updater_log('Failed: ' . $e->getMessage());
            flash('error', $e->getMessage());
        }
        redirect('admin.php#version');
    } elseif ($action === 'site_settings') {
        [$settings, $siteErrors] = site_settings_from_post();
        if ($siteErrors) {
            flash('error', implode(' ', $siteErrors));
        } else {
            try {
                save_site_settings(array_replace_recursive(saved_site_settings(), $settings));
                flash('success', 'Site settings saved.');
            } catch (RuntimeException $e) {
                $_SESSION['site_settings_php'] = site_settings_php($settings);
                flash('error', $e->getMessage());
            }
        }
        redirect('admin.php#site');
    } elseif ($action === 'check_version') {
        $latest = latest_version(true);
        flash($latest && $latest['error'] ? 'error' : 'success', $latest === null ? 'Update checking is switched off in config.php.'
            : ($latest['error'] ?? (update_available($latest) ? 'Version ' . $latest['version'] . ' is available.' : 'Pillbox is up to date.')));
        redirect('admin.php#version');
    } else {
        $target = q('SELECT * FROM users WHERE id = ?', [(int) post('id')])->fetch();
        if (!$target) {
            not_found();
        }
        $self = (int) $target['id'] === (int) $admin['id'];
        switch ($action) {
            case 'approve_signup':
                if ($target['pending_approval']) {
                    approve_signup($target);
                    flash('success', $target['display_name'] . ' approved' . ($target['email'] && mail_enabled() ? ' and emailed.' : '. They can sign in now.'));
                }
                redirect('admin.php#signups');
            case 'decline_signup':
                if ($target['pending_approval']) {
                    decline_signup($target);
                    flash('success', $target['display_name'] . "'s sign-up was declined and removed.");
                }
                redirect('admin.php#signups');
            case 'toggle_active':
                if ($self) {
                    flash('error', "You can't disable your own account.");
                    break;
                }
                q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$target['id']]);
                flash('success', $target['display_name'] . ($target['is_active'] ? ' disabled.' : ' enabled.'));
                break;
            case 'toggle_admin':
                if ($self) {
                    flash('error', "You can't remove your own admin rights.");
                    break;
                }
                q('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$target['id']]);
                flash('success', $target['display_name'] . ($target['is_admin'] ? ' is no longer an admin.' : ' is now an admin.'));
                break;
            case 'reset_password':
                if ($target['auth_provider'] !== 'local') {
                    break;
                }
                if ($e = validate_new_password((string) ($_POST['password'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                    flash('error', $e);
                    break;
                }
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $target['id']]);
                bump_session_version((int) $target['id'], false);
                q('DELETE FROM login_attempts WHERE username = ?', [$target['username']]);
                flash('success', 'Password reset for ' . $target['display_name'] . '.');
                break;
            case 'unlink_entra':
                if ($target['auth_provider'] !== 'entra' && sign_in_method_count($target) > 1) {
                    q('UPDATE users SET entra_oid = NULL, entra_tid = NULL WHERE id = ?', [$target['id']]);
                    flash('success', 'Microsoft account unlinked from ' . $target['display_name'] . '.');
                } else {
                    flash('error', "That would leave {$target['display_name']} with no way to sign in.");
                }
                break;
            case 'unlink_oidc':
                if (sign_in_method_count($target) > 1) {
                    q('DELETE FROM user_identities WHERE user_id = ? AND provider = ?', [$target['id'], post('provider')]);
                    flash('success', 'Sign-in account unlinked from ' . $target['display_name'] . '.');
                } else {
                    flash('error', "That would leave {$target['display_name']} with no way to sign in.");
                }
                break;
            case 'delete':
                if ($self) {
                    flash('error', "You can't delete your own account.");
                    break;
                }
                q('DELETE FROM users WHERE id = ?', [$target['id']]);
                flash('success', $target['display_name'] . ' and all of their data were deleted.');
                break;
        }
        redirect('admin.php');
    }
}

$users = q(
    'SELECT u.*, (SELECT COUNT(*) FROM medications m WHERE m.user_id = u.id AND m.is_active = 1) AS med_count
     FROM users u WHERE u.is_demo = 0 ORDER BY u.display_name'
)->fetchAll();

$userIdentities = [];
foreach (q('SELECT * FROM user_identities ORDER BY provider') as $ident) {
    $userIdentities[$ident['user_id']][] = $ident;
}

$pending = pending_signups();

render_header('Admin', 'admin');
?>
<div class="page-head"><h1>Users <?= help_link('admin', 'users', 'administration') ?></h1></div>
<?= form_errors($errors) ?>

<?php if ($pending): ?>
<section class="card" id="signups">
    <h2>Waiting for approval (<?= count($pending) ?>)</h2>
    <p class="muted small">These people created their own accounts. They can't sign in until you approve them; declining removes the account.</p>
    <ul class="plain">
        <?php foreach ($pending as $u): ?>
        <li class="logline"><span><strong><?= h($u['display_name']) ?></strong>
            <span class="muted small"><?= h($u['email'] ?? $u['username'] ?? '') ?> · <?= h(signup_method_label($u['signup_method'])) ?> · <?= h(local_time($u['created_at'], 'j M Y H:i')) ?></span></span>
            <span class="inline-actions">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-small btn-primary" name="action" value="approve_signup">Approve</button></form>
                <form method="post" data-confirm="Decline <?= h($u['display_name']) ?>'s sign-up and remove the account?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-small" name="action" value="decline_signup">Decline</button></form>
            </span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <?php if (!local_login_enabled()): ?>
    <p class="flash flash-warning">Password sign-in is switched off in <code>config/config.php</code>, so local users can't sign in. Add users for a single sign-on provider instead.</p>
    <?php endif; ?>
    <p class="muted small"><?= signups_open()
        ? 'Sign-ups are open: people can create their own accounts' . (signup_needs_approval() ? ', which wait for an admin to approve them' : '')
            . (config('signup.notify_admins', true) ? (mail_enabled() ? '. Admins with an email address are told about each one.' : '. Admin emails are on, but email isn\'t set up.') : '.')
        : 'Sign-ups are closed: only admins add people (plus any single sign-on set to create accounts automatically).' ?>
        Change this under Site settings below.</p>
    <?php if (!entra_enabled()): ?>
    <p class="muted small">Microsoft Entra ID sign-in is not configured. See the README to switch it on.</p>
    <?php elseif (config('entra.auto_provision')): ?>
    <p class="muted small">Anyone in your permitted Microsoft tenant(s) gets an account automatically the first time they sign in.</p>
    <?php else: ?>
    <p class="muted small">Microsoft users need to be added below before they can sign in.</p>
    <?php endif; ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Sign-in</th><th class="num">Meds</th><th>Last sign-in</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr<?= $u['is_active'] ? '' : ' class="muted"' ?>>
                <td><strong><?= h($u['display_name']) ?></strong><?= $u['is_admin'] ? ' <span class="badge">Admin</span>' : '' ?>
                    <div class="muted small"><?= h($u['username'] ?? $u['email']) ?></div></td>
                <td><?php
                    $ways = [];
                    if ($u['password_hash'] !== null) { $ways[] = 'Local'; }
                    if ($u['auth_provider'] === 'entra' || $u['entra_oid']) { $ways[] = 'Microsoft' . ($u['entra_oid'] ? '' : ' (not yet signed in)'); }
                    foreach ($userIdentities[$u['id']] ?? [] as $ident) {
                        $ways[] = (oidc_provider($ident['provider'])['name'] ?? $ident['provider']) . ($ident['subject'] === null ? ' (not yet signed in)' : '');
                    }
                    echo h(implode(' + ', $ways) ?: '—'); ?></td>
                <td class="num"><?= (int) $u['med_count'] ?></td>
                <td><?= $u['last_login_at'] ? h(local_time($u['last_login_at'], 'j M Y H:i')) : '—' ?></td>
                <td><?= $u['pending_approval'] ? '<a href="#signups">Waiting for approval</a>' : ($u['is_active'] ? 'Active' : 'Disabled') ?></td>
                <td>
                    <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                    <details class="actions-menu">
                        <summary class="btn btn-small">Manage</summary>
                        <div class="actions-pop">
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-small" name="action" value="toggle_active"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
                                <button class="btn btn-small" name="action" value="toggle_admin"><?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?></button>
                                <?php if ($u['auth_provider'] !== 'entra' && $u['entra_oid']): ?>
                                <button class="btn btn-small" name="action" value="unlink_entra">Unlink Microsoft</button>
                                <?php endif; ?>
                            </form>
                            <?php foreach ($userIdentities[$u['id']] ?? [] as $ident): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="provider" value="<?= h($ident['provider']) ?>">
                                <button class="btn btn-small" name="action" value="unlink_oidc">Unlink <?= h(oidc_provider($ident['provider'])['name'] ?? $ident['provider']) ?></button>
                            </form>
                            <?php endforeach; ?>
                            <?php if ($u['auth_provider'] === 'local'): ?>
                            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <label class="sr" for="pw-<?= (int) $u['id'] ?>">New password</label>
                                <input id="pw-<?= (int) $u['id'] ?>" type="password" name="password" minlength="10" required placeholder="New password" autocomplete="new-password">
                                <button class="btn btn-small" type="submit">Reset password</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" data-confirm="Delete <?= h($u['display_name']) ?> and ALL of their medication and health data? This cannot be undone.">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-small btn-danger" name="action" value="delete">Delete user</button>
                            </form>
                        </div>
                    </details>
                    <?php else: ?><span class="muted small">You</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2>Add a user</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <fieldset class="radios">
            <legend>Sign-in type</legend>
            <label class="check"><input type="radio" name="provider" value="local" id="provider-local"<?= checked($new['provider'] === 'local') ?>> Local username &amp; password</label>
            <?php if (entra_enabled()): ?>
            <label class="check"><input type="radio" name="provider" value="entra" id="provider-entra"<?= checked($new['provider'] === 'entra') ?>> Microsoft (Entra ID)</label>
            <?php endif; ?>
            <?php foreach (oidc_providers() as $key => $p): ?>
            <label class="check"><input type="radio" name="provider" value="oidc:<?= h($key) ?>"<?= checked($new['provider'] === 'oidc:' . $key) ?>> <?= h($p['name']) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="grid-2">
            <label>Name <input name="display_name" required maxlength="150" value="<?= h($new['display_name']) ?>"></label>
            <label><span>Email <span class="muted small">(their sign-in address, for Microsoft and other sign-in providers)</span></span> <input type="email" name="email" value="<?= h($new['email']) ?>"></label>
            <label data-show-when="provider-local">Username <input name="username" autocapitalize="none" value="<?= h($new['username']) ?>"></label>
            <label data-show-when="provider-local">Temporary password <input type="password" name="password" minlength="10" autocomplete="new-password"></label>
        </div>
        <label class="check"><input type="checkbox" name="is_admin" value="1"> Administrator</label>
        <button class="btn btn-primary" type="submit">Add user</button>
    </form>
</section>
<?php $latest = latest_version(); ?>
<section class="card" id="site">
    <h2>Site settings</h2>
    <p class="muted small">Saved in <code>config/settings.php</code>, which overrides <code>config/config.php</code>. Database and sign-in settings stay in config.php.</p>
    <?php if (!site_settings_writable()): ?><p class="flash flash-warning">The <code>config/</code> folder isn't writable by the web server: saving will show you what to paste into config.php instead.</p><?php endif; ?>
    <?php if ($paste = $_SESSION['site_settings_php'] ?? null): unset($_SESSION['site_settings_php']); ?><pre class="code"><?= h($paste) ?></pre><?php endif; ?>
    <details class="q-edit"><summary>Change site settings</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="site_settings">
            <?php site_settings_fields(); ?>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save site settings</button></span></form>
    </details>
</section>

<?php $lastRun = tasks_last_run(); ?>
<section class="card" id="tasks">
    <h2>Scheduled tasks</h2>
    <p>Emails (daily summaries, appointment reminders, update notices), the check for a new version on GitHub and tidying up happen in the scheduled tasks.
        <?php if ($lastRun): ?>They last ran <strong><?= h(local_time(gmdate('Y-m-d H:i:s', (int) $lastRun['ran_at']), 'j M Y, H:i')) ?></strong>.
        <?php else: ?><strong>They haven't run yet.</strong><?php endif; ?></p>
    <?php if ($lastRun && $lastRun['ran_at'] < time() - 3600): ?><p class="flash flash-warning">That was over an hour ago: check the cron job is still set up.</p><?php endif; ?>
    <?php if (!empty($lastRun['log'])): ?><details class="q-edit"><summary>What they did</summary><ul class="small"><?php foreach ($lastRun['log'] as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <p class="small">Email is <strong><?= mail_enabled() ? 'on (' . h((string) config('mail.transport', 'mail')) . ')' : 'off' ?></strong> (mail settings in config/config.php).</p>
    <details class="q-edit"<?= $lastRun ? '' : ' open' ?>><summary>How to set them up</summary>
        <p class="small">Run them every 5 to 15 minutes. With cron on your server:</p>
        <pre class="code">*/10 * * * * php <?= h(APP_ROOT) ?>/bin/cron.php</pre>
        <p class="small">Or, if your host has no cron, set a long random <code>token</code> under <code>'cron'</code> in config/config.php and have a web cron service call:</p>
        <pre class="code"><?= h(absolute_url('cron.php?token=')) ?><em>your-token</em></pre>
    </details>
</section>

<section class="card" id="version">
    <h2>Version</h2>
    <p>This Pillbox is version <strong><?= h(pillbox_version()) ?></strong>.
        <?php if ($latest === null): ?>
            Update checking is off (<code>update_check</code> or <code>github_repo</code> in config.php).
        <?php elseif (update_available($latest)): ?>
            <strong>Version <?= h($latest['version']) ?> is available</strong><?= $latest['published_at'] ? ' (released ' . h(date('j M Y', strtotime($latest['published_at']))) . ')' : '' ?>.
        <?php elseif (version_is_dev($latest)): ?>
            <span class="dev-badge">Development version</span> &mdash; ahead of the latest release (<?= h($latest['version']) ?>).
        <?php elseif ($latest['version'] !== null): ?>
            It's up to date (latest release: <?= h($latest['version']) ?>).
        <?php else: ?>
            <span class="warn"><?= h($latest['error']) ?></span>
        <?php endif; ?>
    </p>
    <?php if ($latest !== null): ?><p class="muted small">Last checked <?= h(local_time(gmdate('Y-m-d H:i:s', $latest['checked_at']), 'j M Y, H:i')) ?>, and again each time the scheduled tasks run.
        <?php if ($latest['version'] !== null && $latest['error']): ?><span class="warn">That check failed (<?= h($latest['error']) ?>), so this is the last release found.</span><?php endif; ?></p><?php endif; ?>
    <?php $problems = updater_problems(); if (update_available($latest)): ?>
        <?php if (!$problems): ?>
        <form method="post" class="inline-actions" data-confirm="Install Pillbox <?= h($latest['version']) ?> now? The current files are backed up first.">
            <?= csrf_field() ?><input type="hidden" name="action" value="update_install">
            <button class="btn btn-primary" type="submit">Install version <?= h($latest['version']) ?> now</button>
        </form>
        <p class="muted small">Downloads the release from GitHub, saves a backup of the current files, then replaces them. Your config/config.php
            and storage/ folder are never touched, and the database updates itself on the next page load. It takes a few seconds.</p>
        <?php else: ?>
        <p class="small">To update: download the new version<?= $latest['url'] ? ' from its <a href="' . h($latest['url']) . '" target="_blank" rel="noopener">release page</a>' : '' ?> and upload the files over your current ones,
            keeping your <code>config/config.php</code>. The database updates itself on the next page load.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($problems): ?>
    <details class="q-edit"><summary>Why can't Pillbox update itself here?</summary><ul class="small"><?php foreach ($problems as $pr): ?><li><?= h($pr) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
    <?php if ($backups = updater_backups()): ?>
    <details class="q-edit"><summary>Backups of previous versions (<?= count($backups) ?>)</summary>
        <ul class="plain"><?php foreach ($backups as $bk): ?>
            <li class="logline"><span><?= h($bk['name']) ?> <span class="muted small"><?= h(local_time(gmdate('Y-m-d H:i:s', $bk['time']), 'j M Y, H:i')) ?> &middot; <?= round($bk['size'] / 1048576, 1) ?> MB</span></span>
                <form method="post" data-confirm="Put the files from <?= h($bk['name']) ?> back? (Your data isn't affected.)"><?= csrf_field() ?><input type="hidden" name="action" value="update_restore"><input type="hidden" name="backup" value="<?= h($bk['name']) ?>">
                    <button class="btn btn-small" type="submit">Restore</button></form></li>
        <?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
    <?php if ($log = trim((string) @file_get_contents(storage_path('update.log')))): ?>
    <details class="q-edit"><summary>Update log</summary><pre class="code"><?= h(implode("\n", array_slice(explode("\n", $log), -10))) ?></pre></details>
    <?php endif; ?>
    <span class="inline-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check_version"><button class="btn btn-small" type="submit">Check now</button></form>
    </span>
    <?php if ($changelog = changelog_releases()): ?>
    <details class="q-edit"><summary>Changelog<?= $changelog['releases'] ? ' (' . count($changelog['releases']) . ' releases)' : '' ?></summary>
        <?php if ($changelog['error'] && !$changelog['releases']): ?>
        <p class="warn small"><?= h($changelog['error']) ?></p>
        <?php elseif (!$changelog['releases']): ?>
        <p class="muted small">No releases have been published yet.</p>
        <?php else: ?>
        <?php if ($changelog['error']): ?><p class="warn small">That check failed (<?= h($changelog['error']) ?>), so this is the last changelog found.</p><?php endif; ?>
        <?php foreach ($changelog['releases'] as $release): ?>
        <div class="changelog-entry">
            <h3><?= h($release['version']) ?><?= $release['name'] && $release['name'] !== $release['tag'] ? ': ' . h($release['name']) : '' ?>
                <?php if ($release['published_at']): ?><span class="muted small"><?= h(date('j M Y', strtotime($release['published_at']))) ?></span><?php endif; ?></h3>
            <?= docs_render($release['body'])[1] ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </details>
    <?php endif; ?>
    <details class="q-edit"><summary>Licenses</summary>
        <ul class="plain">
            <?php foreach (software_licenses() as $lic): ?>
            <li class="logline"><span><strong><?= h($lic['name']) ?></strong> &mdash; <?= h($lic['license']) ?>
                <?php if ($lic['note']): ?><br><span class="muted small"><?= h($lic['note']) ?></span><?php endif; ?></span>
                <a class="btn btn-small" href="<?= h(url('license.php?which=' . urlencode($lic['key']))) ?>">View license</a></li>
            <?php endforeach; ?>
        </ul>
    </details>
</section>
<?php render_footer();
