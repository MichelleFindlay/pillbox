<?php
/*
 * First-run setup: create the first administrator. Only works while there are no users.
 */
require __DIR__ . '/../src/bootstrap.php';

if (user_count() > 0) {
    redirect('login.php');
}
$ssoOnly = !local_login_enabled() && sso_enabled();

$errors = [];
$username = mb_strtolower(post('username'));
$name = post('display_name');

if (is_post()) {
    require_post();
    // The setup code proves this is the person installing Pillbox (see setup_code()).
    if (!setup_unlocked()) {
        if (q('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', ['setup'])->fetchColumn() >= 10) {
            $errors[] = 'Too many wrong setup codes. Wait 15 minutes and try again.';
        } elseif (!hash_equals(strtoupper(setup_code()), strtoupper(trim(post('setup_code'))))) {
            q("INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, 'setup', UTC_TIMESTAMP())", [client_ip()]);
            $errors[] = "That setup code isn't right.";
        } else {
            $_SESSION['setup_ok'] = time();
        }
    }
    if (!$errors && !$ssoOnly && post('action') === 'create') {
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '') {
            $errors[] = 'Enter your name.';
        }
        if ($e = validate_username($username)) {
            $errors[] = $e;
        }
        if ($e = validate_new_password($password, (string) ($_POST['password_confirm'] ?? ''))) {
            $errors[] = $e;
        }
        if (!$errors) {
            // One at a time, and only while there's still nobody: never two first administrators.
            q("SELECT GET_LOCK('pillbox_setup', 10)");
            if (user_count() > 0) {
                q("SELECT RELEASE_LOCK('pillbox_setup')");
                redirect('login.php');
            }
            q(
                "INSERT INTO users (username, display_name, password_hash, auth_provider, is_admin, timezone) VALUES (?, ?, ?, 'local', 1, ?)",
                [$username, mb_substr($name, 0, 150), password_hash($password, PASSWORD_DEFAULT), config('app.default_timezone')]
            );
            $id = db()->lastInsertId();
            q("SELECT RELEASE_LOCK('pillbox_setup')");
            setup_finished();
            login_user(q('SELECT * FROM users WHERE id = ?', [$id])->fetch());
            flash('success', 'Welcome! Your administrator account is ready. Start by adding your medications.');
            redirect('medications.php');
        }
    }
}
setup_code(); // make sure there is one to find

render_header('Set up');
?>
<section class="auth-card card">
    <?= logo_svg(96, 'auth-logo') ?>
    <h1>Welcome to <?= h(config('app.name')) ?></h1>
    <p class="muted">Create the first administrator account. You can add more people, or turn on Microsoft sign-in, afterwards.</p>
    <?= form_errors($errors) ?>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <?php if (!setup_unlocked()): ?>
        <label>Setup code <input name="setup_code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" value="<?= h(post('setup_code')) ?>"></label>
        <p class="muted small">To prove you're the person setting up this Pillbox: it's in <code>storage/setup_code.txt</code> on the server, and in the server's PHP error log.
            (Or set <code>'setup_code'</code> under <code>'app'</code> in config.php.)</p>
        <?php endif; ?>
        <?php if ($ssoOnly): ?>
            <?php if (!setup_unlocked()): ?><button class="btn btn-primary btn-block" type="submit">Continue</button>
            <?php else: ?>
        <p>Now sign in: you'll be the administrator.</p>
            <?php endif; ?>
        <?php else: ?>
        <label>Your name <input name="display_name" required maxlength="150" value="<?= h($name) ?>"></label>
        <label>Username <input name="username" required autocapitalize="none" autocomplete="username" value="<?= h($username) ?>"></label>
        <label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block" type="submit">Create administrator</button>
        <?php endif; ?>
    </form>
    <?php if ($ssoOnly && setup_unlocked()): ?><?= sso_buttons() ?><?php endif; ?>
</section>
<?php render_footer();
