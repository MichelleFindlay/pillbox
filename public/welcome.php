<?php
/*
 * First-login setup: about you, what to use, reminders, and (for admins) site settings.
 * Each step saves as it goes; "Skip" moves on, "Skip setup" finishes. Ends on Today.
 * Can be run again from the Account page (welcome.php?again=1).
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
if (isset($user['_acting']) || $user['is_demo']) {
    redirect('index.php');
}
$steps = ['you' => 'About you', 'use' => 'What to use', 'reminders' => 'Reminders'] + ($user['is_admin'] ? ['site' => 'Site settings'] : []);
$keys = array_keys($steps);
$step = isset($steps[get('step')]) ? get('step') : $keys[0];
$next = $keys[array_search($step, $keys, true) + 1] ?? null;
$errors = [];
$pastePhp = null;

$finish = function () use ($uid): never {
    q('UPDATE users SET onboarded_at = UTC_TIMESTAMP() WHERE id = ?', [$uid]);
    flash('success', "You're all set. You can change any of this later in Account settings.");
    redirect('index.php');
};
$advance = fn () => $next ? redirect('welcome.php?step=' . $next) : $finish();

if (is_post()) {
    require_post();
    if (post('skip_all') === '1') {
        $finish();
    }
    if (post('skip') === '1') {
        $advance();
    }
    switch ($step) {
        case 'you':
            $name = trim(mb_substr(post('display_name'), 0, 150));
            $tz = post('timezone');
            $dob = post('date_of_birth') === '' ? null : parse_date(post('date_of_birth'));
            if ($name === '') {
                $errors[] = 'Enter your name.';
            }
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose your time zone.';
            }
            if (post('date_of_birth') !== '' && (!$dob || $dob > local_today())) {
                $errors[] = 'Enter a valid date of birth, or leave it empty.';
            }
            if (!$errors) {
                $sex = isset(SEXES[post('sex')]) ? post('sex') : null;
                q('UPDATE users SET display_name = ?, timezone = ?, date_of_birth = ?, sex = ? WHERE id = ?', [$name, $tz, $dob?->format('Y-m-d'), $sex, $uid]);
                $advance();
            }
            break;
        case 'use':
            $areas = array_values(array_intersect(array_keys(care_categories()), (array) ($_POST['care_areas'] ?? [])));
            $metrics = array_values(array_intersect(array_keys(builtin_metrics()), (array) ($_POST['metrics'] ?? [])));
            q('UPDATE users SET care_areas = ?, mind_enabled = ?, enabled_metrics = ? WHERE id = ?',
                [implode(',', $areas), post('mind_enabled') === '1' ? 1 : 0, implode(',', $metrics), $uid]);
            if ($user['sex'] || $user['date_of_birth']) {
                sync_screening(q('SELECT * FROM users WHERE id = ?', [$uid])->fetch());
            }
            $advance();
        case 'reminders':
            $email = mb_strtolower(trim(post('email')));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address, or leave it empty.';
                break;
            }
            q('UPDATE users SET email = ?, email_digest = ?, email_appointments = ?, push_doses = ?, push_care = ?, push_appointments = ? WHERE id = ?',
                [$email ?: $user['email'], post('email_digest') === '1' ? 1 : 0, post('email_appointments') === '1' ? 1 : 0,
                    post('push_doses') === '1' ? 1 : 0, post('push_care') === '1' ? 1 : 0, post('push_appointments') === '1' ? 1 : 0, $uid]);
            $advance();
        case 'site':
            if (!$user['is_admin']) {
                $advance();
            }
            [$settings, $errors] = site_settings_from_post();
            if (!$errors) {
                try {
                    save_site_settings(array_replace_recursive(saved_site_settings(), $settings));
                    $advance();
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    $pastePhp = site_settings_php($settings);
                }
            }
            break;
    }
}

$user = q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
render_header('Welcome', 'today');
?>
<div class="wizard">
    <div class="page-head">
        <div>
            <h1><?= $step === 'you' ? 'Welcome to ' . h((string) config('app.name', 'Pillbox')) : h($steps[$step]) ?></h1>
            <p class="muted">Step <?= array_search($step, $keys, true) + 1 ?> of <?= count($keys) ?>. Everything here can be changed later in Account settings.</p>
        </div>
    </div>
    <ol class="wizard-steps" aria-label="Setup steps">
        <?php foreach ($steps as $key => $label): $i = array_search($key, $keys, true); $at = array_search($step, $keys, true); ?>
        <li class="<?= $i < $at ? 'done' : ($i === $at ? 'current' : '') ?>"<?= $i === $at ? ' aria-current="step"' : '' ?>><?= h($label) ?></li>
        <?php endforeach; ?>
    </ol>
    <?= form_errors($errors) ?>

    <form method="post" class="card stack">
        <?= csrf_field() ?>
        <?php if ($step === 'you'): ?>
        <p>Pillbox helps you keep track of medicines, health readings, appointments and looking after yourself. A few questions to set it up for you.</p>
        <div class="grid-2">
            <label>Your name <input name="display_name" required maxlength="150" value="<?= h(post('display_name') ?: $user['display_name']) ?>"></label>
            <label>Time zone
                <select name="timezone" id="wiz-tz"><?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option<?= selected($zone === (post('timezone') ?: $user['timezone'])) ?>><?= h($zone) ?></option><?php endforeach; ?></select>
                <button class="btn-link small" type="button" data-use-device-tz="#wiz-tz" hidden>Use this device's time zone</button></label>
            <label><span>Date of birth <span class="muted small">(optional: for screening reminders)</span></span>
                <input type="date" name="date_of_birth" max="<?= h(local_today()->format('Y-m-d')) ?>" value="<?= h(post('date_of_birth') ?: (string) $user['date_of_birth']) ?>"></label>
        </div>
        <fieldset>
            <legend>Sex <span class="muted small">(optional: which NHS screening invitations apply; you can choose them yourself later)</span></legend>
            <div class="repeat-choice">
                <?php foreach (SEXES + ['' => 'Prefer not to say'] as $key => $label): ?>
                <label class="check"><input type="radio" name="sex" value="<?= h($key) ?>"<?= checked((string) $user['sex'] === $key) ?>> <?= h($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <?php elseif ($step === 'use'): $myAreas = user_care_areas($user); $metricsOn = array_keys(enabled_metrics($user)); ?>
        <p>Pillbox always has Today, Meds, Readings and History. Choose what else you'd like; the rest stays out of the way.</p>
        <fieldset>
            <legend>Care: routines and check-ups</legend>
            <div class="checks">
                <?php foreach (care_categories() as $key => $area): ?>
                <label class="check"><input type="checkbox" name="care_areas[]" value="<?= h($key) ?>"<?= checked(in_array($key, $myAreas, true)) ?>> <?= $area['icon'] ?> <?= h($area['title']) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <fieldset>
            <legend>Mind</legend>
            <label class="check"><input type="checkbox" name="mind_enabled" value="1"<?= checked((bool) $user['mind_enabled']) ?>>
                <span>Mood check-ins, coping toolkit, safety plan and journal, with crisis contacts one tap away</span></label>
        </fieldset>
        <fieldset>
            <legend>Measurements to track</legend>
            <div class="checks">
                <?php foreach (builtin_metrics() as $key => $def): ?>
                <label class="check"><input type="checkbox" name="metrics[]" value="<?= h($key) ?>"<?= checked(in_array($key, $metricsOn, true)) ?>> <?= h($def['label']) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <?php elseif ($step === 'reminders'): ?>
        <p>Pillbox can remind you about doses, appointments and anything coming up.</p>
        <?php if (mail_enabled()): ?>
        <fieldset>
            <legend>Email</legend>
            <label class="narrow">Your email address <input type="email" name="email" maxlength="255" value="<?= h((string) $user['email']) ?>"></label>
            <label class="check"><input type="checkbox" name="email_digest" value="1"<?= checked((bool) $user['email_digest']) ?>> <span>A daily summary each morning</span></label>
            <label class="check"><input type="checkbox" name="email_appointments" value="1"<?= checked((bool) $user['email_appointments']) ?>> <span>Appointment reminders</span></label>
        </fieldset>
        <?php else: ?>
        <input type="hidden" name="email" value="<?= h((string) $user['email']) ?>">
        <?php endif; ?>
        <?php if (push_supported()): ?>
        <fieldset>
            <legend>Notifications on this device</legend>
            <div data-push data-csrf="<?= h(csrf_token()) ?>">
                <p class="small" data-push-status>Checking this device&hellip;</p>
                <span class="inline-actions">
                    <button class="btn btn-primary btn-small" type="button" hidden data-push-on>Turn on notifications</button>
                    <button class="btn btn-small" type="button" hidden data-push-test>Send a test</button>
                    <button class="btn btn-small" type="button" hidden data-push-off>Turn off</button>
                </span>
            </div>
            <label class="check"><input type="checkbox" name="push_doses" value="1"<?= checked((bool) $user['push_doses']) ?>> <span>Dose reminders</span></label>
            <label class="check"><input type="checkbox" name="push_care" value="1"<?= checked((bool) $user['push_care']) ?>> <span>Care reminders and what's coming up</span></label>
            <label class="check"><input type="checkbox" name="push_appointments" value="1"<?= checked((bool) $user['push_appointments']) ?>> <span>Appointment reminders</span></label>
        </fieldset>
        <?php endif; ?>
        <p class="muted small">You can also add your appointments to your phone's calendar, from the Appointments page.</p>

        <?php elseif ($step === 'site'): ?>
        <p>As an administrator, you can set up how this Pillbox works for everyone. These are saved in <code>config/settings.php</code>,
            which overrides <code>config/config.php</code> (your database and sign-in settings stay there).</p>
        <?php if (!site_settings_writable()): ?>
        <p class="flash flash-warning">The <code>config/</code> folder isn't writable by the web server, so these can't be saved from here. You'll be shown what to paste into config.php instead.</p>
        <?php endif; ?>
        <?php if ($pastePhp): ?><pre class="code"><?= h($pastePhp) ?></pre><?php endif; ?>
        <?php site_settings_fields(); ?>
        <?php endif; ?>

        <span class="inline-actions wizard-actions">
            <button class="btn btn-primary" type="submit"><?= $next ? 'Next' : 'Finish' ?></button>
            <button class="btn" type="submit" name="skip" value="1" formnovalidate>Skip</button>
            <button class="btn-link small" type="submit" name="skip_all" value="1" formnovalidate>Skip setup</button>
        </span>
    </form>
</div>
<?php render_footer();
