<?php
declare(strict_types=1);

// Colour themes. Colours themselves live in style.css ([data-palette="..."]);
// 'bar' values colour the phone's status bar to match the header.
const THEMES = [
    'olive'    => ['label' => 'Olive (default)', 'bar' => ['#3d4a3a', '#20271e']],
    'nhs'      => ['label' => 'NHS blue',        'bar' => ['#005eb8', '#0b2a4a']],
    'ocean'    => ['label' => 'Ocean',           'bar' => ['#0b5f5a', '#0d2e2c']],
    'berry'    => ['label' => 'Berry',           'bar' => ['#4b2a6e', '#2a1d3d']],
    'sunset'   => ['label' => 'Sunset',          'bar' => ['#7a3415', '#3a1e12']],
    'rose'     => ['label' => 'Rose',            'bar' => ['#7d2349', '#3a1626']],
    'slate'    => ['label' => 'Slate',           'bar' => ['#2c3e50', '#1c2531']],
    'contrast' => ['label' => 'High contrast',   'bar' => ['#000000', '#000000']],
];
const COLOR_MODES = ['auto' => 'Automatic (match my device)', 'light' => 'Light', 'dark' => 'Dark'];

/** The signed-in user's [theme, mode], or the defaults. */
function user_theme(?array $user): array
{
    $theme = isset(THEMES[$user['theme'] ?? '']) ? $user['theme'] : 'olive';
    $mode = isset(COLOR_MODES[$user['color_mode'] ?? '']) ? $user['color_mode'] : 'auto';
    return [$theme, $mode];
}

/** The Pillbox logo (a scored tablet), drawn inline so it takes the current theme's colours. */
function logo_svg(int $size, string $class = ''): string
{
    return '<svg class="logo' . ($class !== '' ? ' ' . h($class) : '') . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" aria-hidden="true" focusable="false">'
        . '<g transform="rotate(-35 32 32)">'
        . '<rect class="logo-edge" x="5" y="23.5" width="54" height="22" rx="11"/>'
        . '<rect class="logo-body" x="5" y="19" width="54" height="22" rx="11"/>'
        . '<path class="logo-score" d="M32 22v16"/>'
        . '</g></svg>';
}

function icon(string $name): string
{
    $paths = [
        'today'    => '<path d="M10 6h10M10 12h10M10 18h10"/><path d="M3.5 6l1.5 1.5L7.5 5M3.5 12l1.5 1.5 2.5-2.5M3.5 18l1.5 1.5 2.5-2.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M8 14h2M14 14h2M8 17.5h2"/>',
        'meds'     => '<rect x="1.5" y="8.5" width="21" height="7" rx="3.5" transform="rotate(-45 12 12)"/><path d="M8.3 8.3l7.4 7.4"/>',
        'readings' => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
        'mind'     => '<path d="M12 21V11"/><path d="M12 13c-4.5 0-7-2.5-7-7 4.5 0 7 2.5 7 7z"/><path d="M12 11c0-4 2.2-6.5 7-6.5 0 4.5-2.5 6.5-7 6.5z"/>',
        'people'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.7-3.6 3.2-5.5 6.5-5.5s5.8 1.9 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.6c2.6.2 4.4 1.9 5 5.4"/>',
        'help'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M5.6 5.6l3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/>',
        'care'     => '<path d="M12 20s-7.5-4.5-7.5-10.2A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 7.5 2.8C19.5 15.5 12 20 12 20z"/>',
        'history'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'report'   => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
        'account'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'admin'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'logout'   => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'github'   => '<path d="M9 19c-4 1.3-4-2-6-2.5M15 21v-3.5c0-1 .1-1.4-.5-2 2.8-.3 5.5-1.4 5.5-6a4.6 4.6 0 0 0-1.3-3.2 4.2 4.2 0 0 0-.1-3.2s-1.1-.3-3.5 1.3a12 12 0 0 0-6.2 0C6.5 2.8 5.4 3.1 5.4 3.1a4.2 4.2 0 0 0-.1 3.2A4.6 4.6 0 0 0 4 9.5c0 4.6 2.7 5.7 5.5 6-.6.6-.6 1.2-.5 2V21"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$name] ?? '') . '</svg>';
}

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $flashes = take_flashes();
    $nav = [
        'today'    => ['index.php', 'Today'],
        'meds'     => ['medications.php', 'Meds'],
        'care'     => ['care.php', 'Care'],
        'mind'     => ['mind.php', 'Mind'],
        'readings' => ['readings.php', 'Readings'],
        'history'  => ['history.php', 'History'],
        'report'   => ['report.php', 'Report'],
    ];
    if (!$user || !mind_enabled($user)) {
        unset($nav['mind']);
    }
    // Shared access: only the tabs that have been shared.
    foreach (['meds' => 'doses', 'history' => 'doses', 'readings' => 'readings', 'care' => 'care', 'report' => 'reports'] as $tab => $section) {
        if ($user && !share_can($section, 'view', $user)) {
            unset($nav[$tab]);
        }
    }
    [$theme, $mode] = user_theme($user);
    [$barLight, $barDark] = THEMES[$theme]['bar'];
    ?><!doctype html>
<html lang="en" data-palette="<?= h($theme) ?>"<?= $mode !== 'auto' ? ' data-theme="' . h($mode) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php if ($mode === 'auto'): ?>
<meta name="theme-color" content="<?= h($barLight) ?>" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="<?= h($barDark) ?>" media="(prefers-color-scheme: dark)">
<?php else: ?>
<meta name="theme-color" content="<?= h($mode === 'dark' ? $barDark : $barLight) ?>">
<?php endif; ?>
<meta name="pillbox-base" content="<?= h(url('')) ?>">
<title><?= h($title) ?> · <?= h(config('app.name')) ?></title>
<link rel="icon" href="<?= h(asset('assets/logo.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= h(asset('assets/icons/icon-192.png')) ?>" type="image/png" sizes="192x192">
<link rel="manifest" href="<?= h(url('manifest.webmanifest')) ?>">
<!-- Installed app ("Add to Home Screen") on iPhone / iPad -->
<link rel="apple-touch-icon" href="<?= h(asset('assets/icons/apple-touch-icon.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= h(config('app.name')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
<script src="<?= h(asset('assets/app.js')) ?>" defer></script>
</head>
<body class="<?= $user ? 'signed-in' : 'signed-out' ?>" data-base="<?= h(config('app.base_path', '')) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
    <a class="brand" href="<?= h(url('index.php')) ?>">
        <?= logo_svg(40) ?>
        <span><?= h(config('app.name')) ?></span>
    </a>
    <?php if ($user): ?>
    <nav class="mainnav" aria-label="Main">
        <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= h(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($key) ?><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="usermenu">
        <?php if (mind_enabled($user)): ?>
        <a class="iconlink help-link" href="<?= h(url('help.php')) ?>" title="Get help now"<?= $active === 'help' ? ' aria-current="page"' : '' ?>><?= icon('help') ?><span class="help-label">Help</span></a>
        <?php endif; ?>
        <?php if (share_can('appointments', 'view', $user)): ?>
        <a class="iconlink" href="<?= h(url('appointments.php')) ?>" title="Appointments"<?= $active === 'appointments' ? ' aria-current="page"' : '' ?>><?= icon('calendar') ?><span class="sr">Appointments</span></a>
        <?php endif; ?>
        <?php if ($user['is_admin'] && !isset($user['_acting'])): ?>
        <a class="iconlink" href="<?= h(url('admin.php')) ?>" title="Admin"<?= $active === 'admin' ? ' aria-current="page"' : '' ?>><?= icon('admin') ?><span class="sr">Admin</span></a>
        <?php endif; ?>
        <a class="iconlink" href="<?= h(url('sharing.php')) ?>" title="Sharing"<?= $active === 'sharing' ? ' aria-current="page"' : '' ?>><?= icon('people') ?><span class="sr">Sharing</span></a>
        <a class="iconlink" href="<?= h(url('account.php')) ?>" title="Account settings"<?= $active === 'account' ? ' aria-current="page"' : '' ?>><?= icon('account') ?><span class="username"><?= h(real_user()['display_name']) ?></span></a>
        <form method="post" action="<?= h(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="iconlink" type="submit" title="Sign out"><?= icon('logout') ?><span class="sr">Sign out</span></button>
        </form>
    </div>
    <?php endif; ?>
</header>
<main id="main" class="container">
<?php if (!empty($user['is_demo'])): ?>
    <div class="acting-banner demo-banner" role="status">
        <span><strong>Demo:</strong> you're Nell Whitcombe, a made-up person. Try anything: it's all cleared when you sign out.</span>
        <form method="post" action="<?= h(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">End demo</button></form>
    </div>
<?php endif; ?>
<?php if (isset($user['_acting'])): ?>
    <div class="acting-banner" role="status">
        <span>You're looking at <strong><?= h($user['display_name']) ?>'s</strong> Pillbox
            <br><span class="muted small"><?= h(share_summary($user['_acting']['perms'])) ?></span></span>
        <form method="post" action="<?= h(url('sharing.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="stop"><input type="hidden" name="return" value="index.php">
            <button class="btn btn-small" type="submit">Back to mine</button></form>
    </div>
<?php endif; ?>
<?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div>
<?php endforeach;
}

function render_footer(): void
{
    $user = current_user();
    $repoUrl = github_repo_url();
    // Only admins can update Pillbox, so only their page views check GitHub.
    $latest = $user && $user['is_admin'] ? latest_version() : null;
    ?>
</main>
<footer class="site-footer">
    <p><?= h(config('app.name')) ?> helps you keep a record. It is not medical advice &mdash; always follow your prescriber's instructions.</p>
    <p class="footer-meta">
        <span>Version <?= h(pillbox_version()) ?></span>
        <?php if ($repoUrl): ?>
        <a href="<?= h($repoUrl) ?>" target="_blank" rel="noopener"><?= icon('github') ?>GitHub</a>
        <?php endif; ?>
        <?php if ($latest !== null): ?>
            <?php if (update_available($latest)): ?>
            <a class="update-badge" href="<?= h(url('admin.php#version')) ?>">Update available: <?= h($latest['version']) ?></a>
            <?php elseif ($latest['version'] !== null): ?>
            <span title="Checked <?= h(local_time(gmdate('Y-m-d H:i:s', $latest['checked_at']), 'j M H:i')) ?>">&#10003; Up to date</span>
            <?php else: ?>
            <a href="<?= h(url('admin.php#version')) ?>" title="<?= h($latest['error']) ?>">Couldn't check for updates</a>
            <?php endif; ?>
        <?php endif; ?>
    </p>
</footer>
</body>
</html>
<?php
}

function med_dot(string $colour): string
{
    $colour = in_array($colour, MED_COLOURS, true) ? $colour : 'grey';
    return '<span class="dot dot-' . $colour . '" aria-hidden="true"></span>';
}

function status_badge(string $status): string
{
    return '<span class="badge badge-' . h($status) . '">' . h(status_label($status)) . '</span>';
}

function form_errors(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $html = '<div class="flash flash-error" role="alert"><ul>';
    foreach ($errors as $e) {
        $html .= '<li>' . h($e) . '</li>';
    }
    return $html . '</ul></div>';
}

function selected(bool $condition): string
{
    return $condition ? ' selected' : '';
}

function checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}

/** One scheduled dose with Take / Skip / Undo buttons. */
function render_dose_item(array $slot, string $return): void
{
    $s = $slot['schedule'];
    $status = $slot['status'];
    ?>
    <li class="dose-item status-<?= h($status) ?>">
        <div class="dose-main">
            <?= med_dot($s['colour']) ?>
            <div>
                <strong><?= h($s['name']) ?></strong> <?= h($s['strength']) ?>
                <div class="muted small">
                    <?= h(dose_label($s['dose_amount'], $s['dose_unit'])) ?>
                    <?php if ($return !== 'index.php' || $slot['local']->format('Y-m-d') !== local_today()->format('Y-m-d')): ?>
                        · <?= h($slot['local']->format('D j M, H:i')) ?>
                    <?php endif; ?>
                    <?= $s['instructions'] ? ' · ' . h($s['instructions']) : '' ?>
                </div>
                <?php if (!empty($slot['home_time']) && $slot['home_time'] !== $slot['local']->format('H:i')): ?>
                    <div class="muted small">&#9992; <?= h($slot['home_time']) ?> home time</div>
                <?php endif; ?>
                <?php if ($slot['log'] && $status === 'taken'): ?>
                    <div class="muted small">Taken at <?= h(local_time($slot['log']['taken_at'], 'H:i')) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="dose-actions">
            <?= status_badge($status) ?>
            <?php if (!share_can('doses', 'log')): ?>
            <?php elseif ($slot['log']): ?>
                <form method="post" action="<?= h(url('dose_action.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="undo">
                    <input type="hidden" name="log_id" value="<?= (int) $slot['log']['id'] ?>">
                    <input type="hidden" name="return" value="<?= h($return) ?>">
                    <button class="btn btn-small" type="submit">Undo</button>
                </form>
            <?php else: ?>
                <?php foreach (['take' => 'Take', 'skip' => 'Skip'] as $action => $label): ?>
                <form method="post" action="<?= h(url('dose_action.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $action ?>">
                    <input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="slot" value="<?= h($slot['utc']) ?>">
                    <input type="hidden" name="return" value="<?= h($return) ?>">
                    <?php if ($status === 'missed'): ?><input type="hidden" name="at" value="scheduled"><?php endif; ?>
                    <button class="btn <?= $action === 'take' ? 'btn-primary' : '' ?> btn-small" type="submit"><?= h($label) ?></button>
                </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </li>
    <?php
}
