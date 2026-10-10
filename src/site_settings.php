<?php
declare(strict_types=1);

/*
 * Site settings an admin can change in Pillbox (first-run setup and the Admin page).
 * They're saved to config/settings.php, which is loaded after config/config.php and
 * overrides it, so config.php itself (with its comments and database details) is
 * never rewritten. Only the settings listed here can be saved this way.
 */

const SITE_SETTINGS_FILE = APP_ROOT . '/config/settings.php';

function site_settings_writable(): bool
{
    return is_file(SITE_SETTINGS_FILE) ? is_writable(SITE_SETTINGS_FILE) : is_writable(dirname(SITE_SETTINGS_FILE));
}

/** The settings currently saved in config/settings.php. */
function saved_site_settings(): array
{
    return is_file(SITE_SETTINGS_FILE) ? (array) (require SITE_SETTINGS_FILE) : [];
}

/** Read the site settings form. @return array{0: array, 1: string[]} [settings to save, errors] */
function site_settings_from_post(): array
{
    $errors = [];
    $name = trim(mb_substr(post('app_name'), 0, 60));
    $publicUrl = rtrim(trim(post('public_url')), '/');
    if ($publicUrl !== '' && !preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $publicUrl)) {
        $errors[] = 'The site address should look like https://pillbox.example.com';
    }
    $tz = post('default_timezone');
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Choose a default time zone.';
    }
    $mailOn = post('mail_enabled') === '1';
    $from = trim(post('mail_from'));
    if ($mailOn && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter the email address Pillbox sends from.';
    }
    $transport = post('mail_transport') === 'smtp' ? 'smtp' : 'mail';
    $port = (int) post('smtp_port');
    if ($mailOn && $transport === 'smtp' && (post('smtp_host') === '' || $port < 1 || $port > 65535)) {
        $errors[] = 'Enter the mail server and port.';
    }
    $token = trim(post('cron_token'));
    if (post('new_cron_token') === '1') {
        $token = bin2hex(random_bytes(20));
    } elseif ($token !== '' && strlen($token) < 16) {
        $errors[] = 'The scheduled tasks token must be at least 16 characters (or leave it empty).';
    }
    $password = (string) ($_POST['smtp_password'] ?? '');
    $connectors = [];
    foreach (array_keys(CONNECTORS) as $key) {
        $secret = trim((string) ($_POST['connector'][$key]['client_secret'] ?? ''));
        $connectors[$key] = [
            'client_id' => trim((string) ($_POST['connector'][$key]['client_id'] ?? '')),
            'client_secret' => $secret !== '' ? $secret : (string) config("connectors.$key.client_secret", ''),
        ];
        if ($connectors[$key]['client_id'] === '') {
            $connectors[$key]['client_secret'] = ''; // no ID: switched off
        }
    }
    return [[
        'app' => [
            'name' => $name ?: 'Pillbox',
            'public_url' => $publicUrl,
            'default_timezone' => $tz,
            'update_check' => post('update_check') === '1',
            'pretty_urls' => post('pretty_urls') === '1' ? null : false,
        ],
        'mail' => [
            'enabled' => $mailOn,
            'from' => $from,
            'from_name' => trim(mb_substr(post('mail_from_name'), 0, 60)) ?: ($name ?: 'Pillbox'),
            'transport' => $transport,
            'smtp' => [
                'host' => trim(post('smtp_host')),
                'port' => $port ?: 587,
                'secure' => in_array(post('smtp_secure'), ['tls', 'ssl', ''], true) ? post('smtp_secure') : 'tls',
                'username' => trim(post('smtp_username')),
                // Left empty: keep the saved password.
                'password' => $password !== '' ? $password : (string) config('mail.smtp.password', ''),
            ],
        ],
        'cron' => ['token' => $token],
        'demo' => ['enabled' => post('demo_enabled') === '1', 'hours' => max(1, min(48, (int) post('demo_hours') ?: 2))],
        'connectors' => $connectors,
    ], $errors];
}

/** Save the settings (replacing config/settings.php in one go). */
function save_site_settings(array $settings): void
{
    $php = "<?php\n// Site settings saved by Pillbox (Admin page / first-run setup). They override config.php.\n// You can edit this file by hand; changes made in Pillbox replace it.\nreturn "
        . var_export($settings, true) . ";\n";
    $tmp = SITE_SETTINGS_FILE . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, SITE_SETTINGS_FILE)) {
        @unlink($tmp);
        throw new RuntimeException("Couldn't write config/settings.php. Make the config/ folder writable by the web server, or copy the settings into config.php by hand.");
    }
    @chmod(SITE_SETTINGS_FILE, 0640);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(SITE_SETTINGS_FILE, true);
    }
}

/** The settings as PHP to paste into config.php, for when the folder isn't writable. */
function site_settings_php(array $settings): string
{
    return "// Paste these into the matching sections of config/config.php:\n" . var_export($settings, true);
}

/** The site settings form fields (current values from the live config). */
function site_settings_fields(): void
{
    $mailOn = (bool) config('mail.enabled', false);
    $transport = (string) config('mail.transport', 'mail');
    $tz = (string) config('app.default_timezone', 'Europe/London');
    ?>
    <fieldset>
        <legend>Your Pillbox</legend>
        <div class="grid-2">
            <label>Site name <input name="app_name" maxlength="60" value="<?= h((string) config('app.name', 'Pillbox')) ?>"></label>
            <label><span>Site address <span class="muted small">(for links in emails and calendars)</span></span>
                <input name="public_url" type="url" placeholder="https://pillbox.example.com" value="<?= h((string) config('app.public_url', '')) ?>"></label>
            <label>Default time zone for new accounts
                <select name="default_timezone"><?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option<?= selected($zone === $tz) ?>><?= h($zone) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="check"><input type="checkbox" name="update_check" value="1"<?= checked((bool) config('app.update_check', true)) ?>> <span>Check GitHub for new versions</span></label>
        <label class="check"><input type="checkbox" name="pretty_urls" value="1"<?= checked(config('app.pretty_urls', null) !== false) ?>> <span>Tidy addresses without ".php" <span class="muted small">(when the server supports them)</span></span></label>
    </fieldset>
    <fieldset>
        <legend>Email</legend>
        <label class="check"><input type="checkbox" name="mail_enabled" value="1" id="mail-on"<?= checked($mailOn) ?>> <span>Send emails (daily summaries, reminders, update notices)</span></label>
        <div class="stack" data-show-when="mail-on">
            <div class="grid-2">
                <label>Send from (email address) <input type="email" name="mail_from" value="<?= h((string) config('mail.from', '')) ?>" placeholder="pillbox@example.com"></label>
                <label>From name <input name="mail_from_name" maxlength="60" value="<?= h((string) config('mail.from_name', 'Pillbox')) ?>"></label>
            </div>
            <div class="repeat-choice">
                <label class="check"><input type="radio" name="mail_transport" value="mail" id="mail-php"<?= checked($transport !== 'smtp') ?>> PHP mail() <span class="muted small">(most shared hosting)</span></label>
                <label class="check"><input type="radio" name="mail_transport" value="smtp" id="mail-smtp"<?= checked($transport === 'smtp') ?>> A mail server (SMTP)</label>
            </div>
            <div class="grid-2" data-show-when="mail-smtp">
                <label>Server <input name="smtp_host" value="<?= h((string) config('mail.smtp.host', '')) ?>" placeholder="smtp.example.com"></label>
                <label>Port <input type="number" name="smtp_port" min="1" max="65535" value="<?= (int) config('mail.smtp.port', 587) ?>"></label>
                <label>Security
                    <select name="smtp_secure"><?php foreach (['tls' => 'STARTTLS (usually 587)', 'ssl' => 'SSL/TLS (usually 465)', '' => 'None'] as $k => $l): ?><option value="<?= h($k) ?>"<?= selected((string) config('mail.smtp.secure', 'tls') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Username <input name="smtp_username" autocomplete="off" value="<?= h((string) config('mail.smtp.username', '')) ?>"></label>
                <label><span>Password <span class="muted small"><?= (string) config('mail.smtp.password', '') !== '' ? '(saved: leave empty to keep it)' : '' ?></span></span>
                    <input type="password" name="smtp_password" autocomplete="new-password"></label>
            </div>
        </div>
    </fieldset>
    <fieldset>
        <legend>Scheduled tasks</legend>
        <p class="muted small">Emails, notifications and tidying up run on a schedule. With cron on the server, run every 5 minutes:</p>
        <pre class="code">*/5 * * * * php <?= h(APP_ROOT) ?>/bin/cron.php</pre>
        <p class="muted small">No cron? Use a token, and have a web cron service call <?= h(absolute_url('cron.php')) ?>?token=&hellip;</p>
        <div class="grid-2">
            <label>Token <span class="muted small">(optional)</span><input name="cron_token" autocomplete="off" value="<?= h((string) config('cron.token', '')) ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="new_cron_token" value="1"> <span>Make a new random token</span></label>
    </fieldset>
    <fieldset>
        <legend>Connected services</legend>
        <p class="muted small">To let people connect WHOOP, Fitbit, Withings or Oura, register a free app with each (a personal or "server" app is fine) and
            give it this address to send people back to:</p>
        <pre class="code"><?= h(connect_redirect_uri()) ?></pre>
        <?php foreach (CONNECTORS as $key => $c): $hasSecret = (string) config("connectors.$key.client_secret", '') !== ''; ?>
        <details class="q-edit"><summary><?= h($c['name']) ?> <span class="muted small"><?= connector_configured($key) ? '(set up)' : '(not set up)' ?></span></summary>
            <p class="small">Register at <a href="<?= h($c['register']) ?>" target="_blank" rel="noopener"><?= h($c['register']) ?></a>, asking for: <code><?= h($c['scope']) ?></code></p>
            <div class="grid-2">
                <label>Client ID <input name="connector[<?= h($key) ?>][client_id]" autocomplete="off" value="<?= h((string) config("connectors.$key.client_id", '')) ?>"></label>
                <label><span>Client secret <span class="muted small"><?= $hasSecret ? '(saved: leave empty to keep it)' : '' ?></span></span>
                    <input type="password" name="connector[<?= h($key) ?>][client_secret]" autocomplete="new-password"></label>
            </div>
        </details>
        <?php endforeach; ?>
        <p class="muted small">MyFitnessPal and Google Fit don't allow connections like this: people import their export files instead.</p>
    </fieldset>
    <fieldset>
        <legend>Demo</legend>
        <label class="check"><input type="checkbox" name="demo_enabled" value="1" id="demo-on"<?= checked((bool) config('demo.enabled', false)) ?>> <span>Show "Try the demo" on the sign-in page</span></label>
        <label class="narrow" data-show-when="demo-on">Clear abandoned demos after (hours) <input type="number" name="demo_hours" min="1" max="48" value="<?= (int) config('demo.hours', 2) ?>"></label>
    </fieldset>
    <?php
}
