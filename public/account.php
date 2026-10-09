<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$errors = [];

if (is_post()) {
    require_post();
    switch (post('action')) {
        case 'profile':
            $name = post('display_name');
            $tz = post('timezone');
            if ($name === '' || mb_strlen($name) > 150) {
                $errors[] = 'Enter your name (up to 150 characters).';
            }
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose a valid timezone.';
            }
            if (!$errors) {
                q('UPDATE users SET display_name = ?, timezone = ? WHERE id = ?', [$name, $tz, $uid]);
                flash('success', 'Profile saved.');
                redirect('account.php');
            }
            break;

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

        case 'password':
            if ($user['auth_provider'] !== 'local') {
                break;
            }
            if (!password_verify((string) ($_POST['current'] ?? ''), (string) $user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif ($e = validate_new_password((string) ($_POST['new'] ?? ''), (string) ($_POST['confirm'] ?? ''))) {
                $errors[] = $e;
            } else {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $_POST['new'], PASSWORD_DEFAULT), $uid]);
                session_regenerate_id(true);
                flash('success', 'Password changed.');
                redirect('account.php');
            }
            break;
    }
}

$enabled = $user['enabled_metrics'] !== null ? explode(',', $user['enabled_metrics']) : DEFAULT_METRICS;
$custom = q('SELECT * FROM custom_metrics WHERE user_id = ? ORDER BY label', [$uid])->fetchAll();

render_header('Account', 'account');
?>
<div class="page-head"><h1>Account settings</h1></div>
<?= form_errors($errors) ?>

<section class="card">
    <h2>Profile</h2>
    <p class="muted small">
        Signed in with <?= $user['auth_provider'] === 'entra' ? 'Microsoft (Entra ID)' : 'a local account' ?>
        <?= $user['username'] ? ' as <strong>' . h($user['username']) . '</strong>' : '' ?>
        <?= $user['email'] ? ' · ' . h($user['email']) : '' ?>
    </p>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <div class="grid-2">
            <label>Name <input name="display_name" required maxlength="150" value="<?= h($user['display_name']) ?>"></label>
            <label>Timezone
                <select name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= selected($tz === $user['timezone']) ?>><?= h($tz) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <button class="btn btn-primary" type="submit">Save profile</button>
    </form>
</section>

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

<?php if ($user['auth_provider'] === 'local'): ?>
<section class="card">
    <h2>Change password</h2>
    <form method="post" class="stack narrow">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
        <label>New password <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Change password</button>
    </form>
</section>
<?php endif; ?>
<?php render_footer();
