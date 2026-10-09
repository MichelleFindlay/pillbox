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
        $new = ['provider' => post('provider') === 'entra' ? 'entra' : 'local', 'username' => mb_strtolower(post('username')),
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
            if ($new['email'] === '') {
                $errors[] = 'Microsoft users are matched by their sign-in email address, so enter it.';
            } elseif (q('SELECT 1 FROM users WHERE email = ?', [$new['email']])->fetch()) {
                $errors[] = 'A user with that email address already exists.';
            }
        }
        if (!$errors) {
            q(
                'INSERT INTO users (username, email, display_name, password_hash, auth_provider, is_admin, timezone) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $new['provider'] === 'local' ? $new['username'] : null,
                    $new['email'] ?: null,
                    $new['display_name'],
                    $new['provider'] === 'local' ? password_hash((string) $_POST['password'], PASSWORD_DEFAULT) : null,
                    $new['provider'],
                    isset($_POST['is_admin']) ? 1 : 0,
                    config('app.default_timezone'),
                ]
            );
            flash('success', $new['display_name'] . ' added.');
            redirect('admin.php');
        }
    } else {
        $target = q('SELECT * FROM users WHERE id = ?', [(int) post('id')])->fetch();
        if (!$target) {
            not_found();
        }
        $self = (int) $target['id'] === (int) $admin['id'];
        switch ($action) {
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
                q('DELETE FROM login_attempts WHERE username = ?', [$target['username']]);
                flash('success', 'Password reset for ' . $target['display_name'] . '.');
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
     FROM users u ORDER BY u.display_name'
)->fetchAll();

render_header('Admin', 'admin');
?>
<div class="page-head"><h1>Users</h1></div>
<?= form_errors($errors) ?>

<section class="card">
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
                <td><?= $u['auth_provider'] === 'entra' ? 'Microsoft' . ($u['entra_oid'] ? '' : ' <span class="muted small">(not yet signed in)</span>') : 'Local' ?></td>
                <td class="num"><?= (int) $u['med_count'] ?></td>
                <td><?= $u['last_login_at'] ? h(local_time($u['last_login_at'], 'j M Y H:i')) : '—' ?></td>
                <td><?= $u['is_active'] ? 'Active' : 'Disabled' ?></td>
                <td>
                    <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                    <details class="actions-menu">
                        <summary class="btn btn-small">Manage</summary>
                        <div class="actions-pop">
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-small" name="action" value="toggle_active"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
                                <button class="btn btn-small" name="action" value="toggle_admin"><?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?></button>
                            </form>
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
            <label class="check"><input type="radio" name="provider" value="entra" id="provider-entra"<?= checked($new['provider'] === 'entra') ?>> Microsoft (Entra ID)</label>
        </fieldset>
        <div class="grid-2">
            <label>Name <input name="display_name" required maxlength="150" value="<?= h($new['display_name']) ?>"></label>
            <label><span>Email <span class="muted small">(Microsoft sign-in address for Entra users)</span></span> <input type="email" name="email" value="<?= h($new['email']) ?>"></label>
            <label data-show-when="provider-local">Username <input name="username" autocapitalize="none" value="<?= h($new['username']) ?>"></label>
            <label data-show-when="provider-local">Temporary password <input type="password" name="password" minlength="10" autocomplete="new-password"></label>
        </div>
        <label class="check"><input type="checkbox" name="is_admin" value="1"> Administrator</label>
        <button class="btn btn-primary" type="submit">Add user</button>
    </form>
</section>
<?php render_footer();
