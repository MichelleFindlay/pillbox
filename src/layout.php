<?php
declare(strict_types=1);

function icon(string $name): string
{
    $paths = [
        'today'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'meds'     => '<rect x="1.5" y="8.5" width="21" height="7" rx="3.5" transform="rotate(-45 12 12)"/><path d="M8.3 8.3l7.4 7.4"/>',
        'readings' => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
        'history'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'report'   => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
        'account'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'admin'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'logout'   => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
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
        'readings' => ['readings.php', 'Readings'],
        'history'  => ['history.php', 'History'],
        'report'   => ['report.php', 'Report'],
    ];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#3d4a3a">
<title><?= h($title) ?> · <?= h(config('app.name')) ?></title>
<link rel="icon" href="<?= h(asset('assets/logo.svg')) ?>" type="image/svg+xml">
<link rel="manifest" href="<?= h(url('manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
<script src="<?= h(asset('assets/app.js')) ?>" defer></script>
</head>
<body class="<?= $user ? 'signed-in' : 'signed-out' ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
    <a class="brand" href="<?= h(url('index.php')) ?>">
        <img src="<?= h(asset('assets/logo.svg')) ?>" alt="" width="40" height="40">
        <span><?= h(config('app.name')) ?></span>
    </a>
    <?php if ($user): ?>
    <nav class="mainnav" aria-label="Main">
        <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= h(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($key) ?><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="usermenu">
        <?php if ($user['is_admin']): ?>
        <a class="iconlink" href="<?= h(url('admin.php')) ?>" title="Admin"<?= $active === 'admin' ? ' aria-current="page"' : '' ?>><?= icon('admin') ?><span class="sr">Admin</span></a>
        <?php endif; ?>
        <a class="iconlink" href="<?= h(url('account.php')) ?>" title="Account settings"<?= $active === 'account' ? ' aria-current="page"' : '' ?>><?= icon('account') ?><span class="username"><?= h($user['display_name']) ?></span></a>
        <form method="post" action="<?= h(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="iconlink" type="submit" title="Sign out"><?= icon('logout') ?><span class="sr">Sign out</span></button>
        </form>
    </div>
    <?php endif; ?>
</header>
<main id="main" class="container">
<?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div>
<?php endforeach;
}

function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <p><?= h(config('app.name')) ?> helps you keep a record. It is not medical advice &mdash; always follow your prescriber's instructions.</p>
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
                <?php if ($slot['log'] && $status === 'taken'): ?>
                    <div class="muted small">Taken at <?= h(local_time($slot['log']['taken_at'], 'H:i')) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="dose-actions">
            <?= status_badge($status) ?>
            <?php if ($slot['log']): ?>
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
