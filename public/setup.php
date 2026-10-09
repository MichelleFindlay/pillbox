<?php
/*
 * First-run setup: create the first administrator. Only works while there are no users.
 */
require __DIR__ . '/../src/bootstrap.php';

if (user_count() > 0) {
    redirect('login.php');
}

$errors = [];
$username = mb_strtolower(post('username'));
$name = post('display_name');

if (is_post()) {
    require_post();
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
        q(
            "INSERT INTO users (username, display_name, password_hash, auth_provider, is_admin, timezone) VALUES (?, ?, ?, 'local', 1, ?)",
            [$username, mb_substr($name, 0, 150), password_hash($password, PASSWORD_DEFAULT), config('app.default_timezone')]
        );
        login_user(q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch());
        flash('success', 'Welcome! Your administrator account is ready. Start by adding your medications.');
        redirect('medications.php');
    }
}

render_header('Set up');
?>
<section class="auth-card card">
    <img class="auth-logo" src="<?= h(asset('assets/logo.svg')) ?>" alt="" width="96" height="96">
    <h1>Welcome to <?= h(config('app.name')) ?></h1>
    <p class="muted">Create the first administrator account. You can add more people, or turn on Microsoft sign-in, afterwards.</p>
    <?= form_errors($errors) ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <label>Your name <input name="display_name" required maxlength="150" value="<?= h($name) ?>"></label>
        <label>Username <input name="username" required autocapitalize="none" autocomplete="username" value="<?= h($username) ?>"></label>
        <label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block" type="submit">Create administrator</button>
    </form>
</section>
<?php render_footer();
