<?php
/*
 * Connect apps and devices: Apple Health and Android (Health Connect) sync, WHOOP / Fitbit /
 * Withings / Oura connections, and MyFitnessPal and Google Fit export files.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$tz = user_tz();

if (is_post()) {
    require_post();
    demo_blocked('Connecting apps');
    $provider = isset(CONNECTORS[post('provider')]) ? post('provider') : null;
    $conn = $provider ? q('SELECT * FROM connections WHERE user_id = ? AND provider = ?', [$uid, $provider])->fetch() : null;
    switch (post('action')) {
        case 'connect':
            if ($provider && connector_configured($provider)) {
                redirect(connector_start($provider));
            }
            break;
        case 'sync':
            if ($conn) {
                try {
                    $r = sync_connection($conn, 30);
                    flash('success', CONNECTORS[$provider]['name'] . ": {$r['added']} new and {$r['updated']} updated readings.");
                } catch (Throwable $e) {
                    flash('error', CONNECTORS[$provider]['name'] . ': ' . $e->getMessage());
                }
            }
            break;
        case 'disconnect':
            if ($conn) {
                q('DELETE FROM connections WHERE id = ?', [$conn['id']]);
                $removed = post('remove_readings') === '1' ? q('DELETE FROM health_readings WHERE user_id = ? AND source = ?', [$uid, $provider])->rowCount() : 0;
                flash('success', CONNECTORS[$provider]['name'] . ' disconnected' . ($removed ? " and $removed readings removed." : '. Readings already saved are kept.'));
            }
            break;
        case 'import_mfp':
        case 'import_google_fit':
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                flash('error', 'Choose the file to import' . ($file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ' (that one is too big for this server)' : '') . '.');
                break;
            }
            try {
                $r = post('action') === 'import_mfp'
                    ? import_myfitnesspal($uid, $file['tmp_name'], $file['name'], post('weight_unit'), $tz)
                    : import_google_fit($uid, $file['tmp_name'], $file['name'], $tz);
                flash('success', "Imported: {$r['added']} new and {$r['updated']} updated readings"
                    . ($r['metrics'] ? ' (' . implode(', ', array_map(fn ($m) => mb_strtolower(builtin_metrics()[$m]['label'] ?? $m), array_keys($r['metrics']))) . ')' : '') . '.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
            break;
    }
    redirect('connections.php');
}

$connections = array_column(q('SELECT * FROM connections WHERE user_id = ?', [$uid])->fetchAll(), null, 'provider');
$counts = array_column(q('SELECT source, COUNT(*) AS n FROM health_readings WHERE user_id = ? AND source IS NOT NULL GROUP BY source', [$uid])->fetchAll(), 'n', 'source');

render_header('Connect apps and devices', 'readings');
?>
<div class="page-head">
    <div>
        <h1>Connect apps and devices <?= help_link('readings', 'connecting-apps-and-devices', 'connecting apps') ?></h1>
        <p class="muted">Bring readings in from your phone, watch, ring, strap or scales. Imported readings are labelled with where they came from.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('readings.php')) ?>">&larr; Readings</a>
</div>

<section class="card">
    <h2>Phones</h2>
    <div class="connect-row">
        <div><strong>Apple Health</strong> <span class="muted small">(iPhone and Apple Watch, plus apps that write to it, like MyFitnessPal)</span>
            <?= isset($counts['apple_health']) ? '<br><span class="small">' . (int) $counts['apple_health'] . ' readings so far</span>' : '' ?></div>
        <a class="btn btn-small" href="<?= h(url('health_import.php')) ?>">Import or set up sync</a>
    </div>
    <div class="connect-row">
        <div><strong>Android: Health Connect</strong> <span class="muted small">(Samsung Health, Google Fit, Fitbit and most Android health apps share their data through it)</span>
            <p class="small muted">Health Connect only works on the phone itself, so it needs an app that sends its data to a web address. Make a private sync link on the
                <a href="<?= h(url('health_import.php#sync')) ?>">Apple Health page</a> (it works for any app) and give it to an Android app that can send Health Connect
                data to a URL as JSON, in the format shown there.</p></div>
    </div>
</section>

<section class="card">
    <h2>Watches, rings, straps and scales</h2>
    <?php foreach (CONNECTORS as $key => $c): $conn = $connections[$key] ?? null; ?>
    <div class="connect-row" id="<?= h($key) ?>">
        <div>
            <strong><?= h($c['name']) ?></strong> <span class="muted small"><?= h($c['gives']) ?></span>
            <?php if ($conn): ?>
            <br><span class="small"><span class="badge badge-taken">Connected</span> since <?= h(local_time($conn['created_at'], 'j M Y')) ?>
                <?= $conn['last_sync_at'] ? ' · last synced ' . h(local_time($conn['last_sync_at'], 'j M, H:i')) : '' ?> · <?= (int) ($counts[$key] ?? 0) ?> readings</span>
            <?php if ($conn['last_error']): ?><br><span class="small warn">Last sync failed: <?= h($conn['last_error']) ?></span><?php endif; ?>
            <?php elseif (!connector_configured($key)): ?>
            <br><span class="small muted"><?= $user['is_admin'] ? 'Not set up yet: add its app keys under Admin, Site settings.' : 'Not available yet: ask your administrator to set it up.' ?></span>
            <?php endif; ?>
        </div>
        <span class="inline-actions">
            <?php if ($conn): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="provider" value="<?= h($key) ?>">
                <button class="btn btn-small" type="submit">Sync now</button></form>
            <details class="q-edit disconnect"><summary class="small">Disconnect</summary>
                <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><input type="hidden" name="provider" value="<?= h($key) ?>">
                    <label class="check small"><input type="checkbox" name="remove_readings" value="1"> <span>Also remove the readings it added</span></label>
                    <button class="btn btn-small btn-danger" type="submit">Disconnect <?= h($c['name']) ?></button></form>
            </details>
            <?php elseif (connector_configured($key)): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect"><input type="hidden" name="provider" value="<?= h($key) ?>">
                <button class="btn btn-small btn-primary" type="submit">Connect</button></form>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>
    <p class="muted small">Connected services sync about once an hour. Garmin only shares data with approved businesses, so it can't connect directly:
        let Garmin Connect write to Apple Health or Health Connect instead.</p>
</section>

<section class="card">
    <h2>Import a file</h2>
    <div class="connect-row">
        <div><strong>MyFitnessPal</strong> <span class="muted small">(weight, and calories eaten each day)</span>
            <p class="small muted">MyFitnessPal doesn't let other apps connect. In MyFitnessPal, use <em>Settings, Export data</em> (a Premium feature) and upload the zip
                or CSV it emails you. Or let MyFitnessPal write to Apple Health or Health Connect, and import from there.</p>
            <form method="post" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="import_mfp">
                <label>File <input type="file" name="file" accept=".zip,.csv" required></label>
                <label>Weight is in <select name="weight_unit"><option value="kg">kg</option><option value="lb">pounds</option><option value="st">stone</option></select></label>
                <button class="btn btn-small" type="submit">Import</button></form>
            <?= isset($counts['myfitnesspal']) ? '<span class="small">' . (int) $counts['myfitnesspal'] . ' readings so far</span>' : '' ?>
        </div>
    </div>
    <div class="connect-row">
        <div><strong>Google Fit</strong> <span class="muted small">(steps and weight history)</span>
            <p class="small muted">Google Fit is being retired. To keep your history, download it from <em>Google Takeout</em> (choose Fit), then upload
                <em>Daily activity metrics.csv</em> or the whole zip.</p>
            <form method="post" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="import_google_fit">
                <label>File <input type="file" name="file" accept=".zip,.csv" required></label>
                <button class="btn btn-small" type="submit">Import</button></form>
            <?= isset($counts['google_fit']) ? '<span class="small">' . (int) $counts['google_fit'] . ' readings so far</span>' : '' ?>
        </div>
    </div>
</section>
<?php render_footer();
