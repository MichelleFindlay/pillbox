<?php
/*
 * Apple Health: import the Health app's export file, set up automatic sync,
 * or remove imported readings.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];

const HEALTH_MAX_UPLOAD = 4 * 1024 * 1024 * 1024; // 4 GB
const HEALTH_RANGES = ['30' => 'Last 30 days', '90' => 'Last 3 months', '365' => 'Last year', 'all' => 'Everything'];

function health_upload_dir(): string
{
    $dir = storage_path('uploads');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // Tidy away abandoned uploads.
    foreach (glob($dir . '/health_*') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    return $dir;
}

function ini_bytes(string $value): int
{
    $n = (float) $value;
    return (int) match (strtolower(substr(trim($value), -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
}

/** Largest upload piece this server accepts in one request (kept well under its limits). */
function health_chunk_size(): int
{
    $limits = array_filter([ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size'))]);
    return (int) max(256 * 1024, min(8 * 1024 * 1024, ($limits ? min($limits) : 2 * 1024 * 1024) * 0.8));
}

function run_health_import(array $user, string $path, string $range): string
{
    set_time_limit(0);
    ignore_user_abort(true);
    $since = isset(HEALTH_RANGES[$range]) && $range !== 'all' ? new DateTimeImmutable("-$range days") : null;
    $importer = new HealthImporter(home_tz($user), $since);
    read_apple_health_export($path, $importer);
    $result = save_health_rows((int) $user['id'], $importer->rows());
    return health_import_summary($result, $importer);
}

function json_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if (is_post()) {
    demo_blocked('Importing and syncing Apple Health');
    // PHP throws away a form that's bigger than post_max_size, so there's nothing to check.
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes((string) ini_get('post_max_size'))) {
        flash('error', 'That file is bigger than this server accepts in one go. Turn on JavaScript in your browser so Pillbox can upload it in pieces.');
        redirect('health_import.php');
    }
    require_post();
    switch (post('action')) {
        // Large exports arrive in pieces from the browser, then get imported in one go.
        case 'upload_chunk':
            $id = post('upload_id');
            $index = (int) post('index');
            $chunk = $_FILES['chunk'] ?? null;
            if (!preg_match('/^[a-f0-9]{32}$/', $id) || !$chunk || $chunk['error'] !== UPLOAD_ERR_OK) {
                json_reply(['ok' => false, 'error' => 'Part of the upload went missing. Please try again.'], 400);
            }
            $path = health_upload_dir() . "/health_{$uid}_{$id}.part";
            $state = $index === 0 ? ['next' => 0] : json_decode((string) @file_get_contents("$path.json"), true);
            if (!is_array($state) || $state['next'] !== $index) {
                json_reply(['ok' => false, 'error' => 'The upload got out of step. Please try again.'], 409);
            }
            if (($index === 0 ? 0 : filesize($path)) + $chunk['size'] > HEALTH_MAX_UPLOAD) {
                json_reply(['ok' => false, 'error' => 'That file is too big.'], 413);
            }
            $in = fopen($chunk['tmp_name'], 'rb');
            $out = fopen($path, $index === 0 ? 'wb' : 'ab');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            file_put_contents("$path.json", json_encode(['next' => $index + 1]));
            json_reply(['ok' => true]);

        case 'import_upload':
            $id = post('upload_id');
            $path = health_upload_dir() . "/health_{$uid}_{$id}.part";
            if (!preg_match('/^[a-f0-9]{32}$/', $id) || !is_file($path)) {
                json_reply(['ok' => false, 'error' => 'The uploaded file could not be found. Please try again.'], 400);
            }
            $error = null;
            try {
                $summary = run_health_import($user, $path, post('range'));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            // Never keep the export on the server, whatever happened.
            @unlink($path);
            @unlink("$path.json");
            if ($error !== null) {
                json_reply(['ok' => false, 'error' => $error], 422);
            }
            flash('success', $summary);
            json_reply(['ok' => true, 'redirect' => url('health_import.php')]);

        // Without JavaScript: an ordinary single upload (subject to the server's size limit).
        case 'import_file':
            $file = $_FILES['export'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                flash('error', $file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'That file is bigger than this server allows in one go. Turn on JavaScript to upload it in pieces.'
                    : 'Choose your export.zip file first.');
                redirect('health_import.php');
            }
            try {
                flash('success', run_health_import($user, $file['tmp_name'], post('range')));
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
            redirect('health_import.php');

        case 'sync_create':
        case 'sync_regenerate':
            q('UPDATE users SET health_sync_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), $uid]);
            flash('success', post('action') === 'sync_create' ? 'Your sync link is ready.' : 'New sync link created. Update it in your iPhone automation; the old one has stopped working.');
            redirect('health_import.php#sync');

        case 'sync_off':
            q('UPDATE users SET health_sync_token = NULL WHERE id = ?', [$uid]);
            flash('success', 'Automatic sync turned off.');
            redirect('health_import.php#sync');

        case 'remove_imported':
            $n = q('DELETE FROM health_readings WHERE user_id = ? AND source = ?', [$uid, HEALTH_SOURCE])->rowCount();
            flash('success', "Removed $n reading" . ($n === 1 ? '' : 's') . ' imported from Apple Health. Readings you entered yourself were kept.');
            redirect('health_import.php');
    }
    redirect('health_import.php');
}

$imported = q('SELECT metric, COUNT(*) AS n, MIN(recorded_at) AS first, MAX(recorded_at) AS last FROM health_readings
               WHERE user_id = ? AND source = ? GROUP BY metric ORDER BY metric', [$uid, HEALTH_SOURCE])->fetchAll();
$syncUrl = $user['health_sync_token'] ? absolute_url('health_sync.php?token=' . $user['health_sync_token']) : null;
$defs = builtin_metrics();
$canZip = class_exists(ZipArchive::class);

render_header('Apple Health', 'readings');
?>
<div class="page-head">
    <h1>Apple Health</h1>
    <a class="btn btn-small" href="<?= h(url('readings.php')) ?>">&larr; Readings</a>
</div>
<p class="muted">Bring readings from the Apple Health app into Pillbox: blood pressure, weight, blood glucose, temperature, peak flow,
    and a daily figure for resting heart rate, oxygen level, breathing rate, steps and sleep. Apple doesn't let websites read
    Health directly, so there are two ways to do it.</p>

<section class="card">
    <h2>1. Import your Health export</h2>
    <ol class="steps">
        <li>On your iPhone, open the <strong>Health</strong> app and tap your <strong>picture or initials</strong> (top right).</li>
        <li>Scroll down and tap <strong>Export All Health Data</strong>, then <strong>Export</strong>. It can take a few minutes.</li>
        <li>Choose <strong>Save to Files</strong>. You can upload it right here from your phone, or send it to a computer.</li>
        <li>Choose the <strong>export.zip</strong> file below and tap <strong>Import</strong>.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="stack" id="health-upload"
          data-chunk-size="<?= health_chunk_size() ?>" action="<?= h(url('health_import.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_file">
        <div class="grid-2">
            <label>Your export file <span class="muted small">(export.zip<?= $canZip ? '' : ' unzipped: export.xml' ?>)</span>
                <input type="file" name="export" accept=".zip,.xml,application/zip,text/xml" required></label>
            <label>Import readings from
                <select name="range">
                    <?php foreach (HEALTH_RANGES as $key => $label): ?><option value="<?= $key ?>"<?= selected((string) $key === '365') ?>><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="upload-progress" hidden>
            <progress max="100" value="0"></progress>
            <p class="muted small" aria-live="polite"></p>
        </div>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Import</button></span>
        <p class="muted small">You can import again whenever you like. Readings already in Pillbox aren't duplicated. Only the
            measurements listed above are read; the file is deleted as soon as it has been imported.</p>
    </form>
</section>

<section class="card" id="sync">
    <h2>2. Sync automatically</h2>
    <?php if (!$syncUrl): ?>
        <p>Set up an automation on your iPhone that sends new readings to Pillbox every day, using Apple's free
            <strong>Shortcuts</strong> app or the <strong>Health Auto Export</strong> app. It needs a private sync link.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_create">
            <button class="btn btn-primary" type="submit">Create my sync link</button></form>
    <?php else: ?>
        <p class="small">Your private sync link. <strong>Anyone with it can add readings to your Pillbox</strong>, so keep it to yourself.</p>
        <div class="copy-row">
            <label class="sr" for="sync-url">Sync link</label>
            <input id="sync-url" type="text" readonly value="<?= h($syncUrl) ?>">
            <button class="btn" type="button" data-copy="#sync-url">Copy</button>
        </div>
        <details class="subscribe-help" open>
            <summary><strong>Health Auto Export app</strong> (easiest)</summary>
            <ol class="steps small">
                <li>Install <em>Health Auto Export – JSON+CSV</em> from the App Store and allow it to read your Health data.</li>
                <li>Go to <strong>Automations → New automation → REST API</strong>.</li>
                <li>Paste your sync link as the <strong>URL</strong>. Set <strong>Export format</strong> to <strong>JSON</strong> and <strong>Date range</strong> to <strong>Since last sync</strong>.</li>
                <li>Under <strong>Health metrics</strong>, pick the ones you want (blood pressure, weight, blood glucose, resting heart rate, blood oxygen, respiratory rate, body temperature, step count, sleep analysis).</li>
                <li>Choose how often to sync and turn the automation on.</li>
            </ol>
        </details>
        <details class="subscribe-help">
            <summary><strong>Apple Shortcuts</strong> (free, a little more setting up)</summary>
            <ol class="steps small">
                <li>In <strong>Shortcuts</strong>, create a shortcut with <strong>Find Health Samples</strong> (e.g. Type: Weight, Start Date is in the last 7 days).</li>
                <li>Add <strong>Repeat with Each</strong>. Inside it, add a <strong>Dictionary</strong> with <code>type</code> = the sample's Type, <code>value</code> = its Value, <code>unit</code> = its Unit, <code>date</code> = its Start Date. Then <strong>Add to Variable</strong> "samples".</li>
                <li>After the loop add <strong>Dictionary</strong> with key <code>samples</code> = the "samples" variable, then <strong>Get Contents of URL</strong>: your sync link, Method <strong>POST</strong>, Request Body <strong>File</strong> = that dictionary.</li>
                <li>Repeat steps 1–2 for each type you want (blood pressure: send <code>systolic</code> and <code>diastolic</code> instead of value), then add a daily <strong>Personal Automation</strong> to run it.</li>
            </ol>
            <p class="small">What Pillbox expects:</p>
            <pre class="code small">{"samples": [
  {"type": "Weight", "value": 72.4, "unit": "kg", "date": "2026-10-09T07:30:00+01:00"},
  {"type": "blood_pressure", "systolic": 128, "diastolic": 82, "date": "2026-10-09T08:00:00+01:00"}
]}</pre>
        </details>
        <div class="feed-actions">
            <form method="post" data-confirm="Make a new sync link? The current one will stop working."><?= csrf_field() ?><input type="hidden" name="action" value="sync_regenerate">
                <button class="btn btn-small" type="submit">Make a new link</button></form>
            <form method="post" data-confirm="Turn off automatic sync?"><?= csrf_field() ?><input type="hidden" name="action" value="sync_off">
                <button class="btn btn-small btn-danger" type="submit">Turn off sync</button></form>
        </div>
    <?php endif; ?>
</section>

<?php if ($imported): ?>
<section class="card">
    <h2>Imported so far</h2>
    <div class="table-wrap">
    <table class="compact">
        <thead><tr><th>Measurement</th><th class="num">Readings</th><th>From</th><th>To</th></tr></thead>
        <tbody>
        <?php foreach ($imported as $row): ?>
            <tr><td><a href="<?= h(url('readings.php?metric=' . urlencode($row['metric']))) ?>"><?= h($defs[$row['metric']]['label'] ?? $row['metric']) ?></a></td>
                <td class="num"><?= (int) $row['n'] ?></td><td><?= h(local_time($row['first'], 'j M Y')) ?></td><td><?= h(local_time($row['last'], 'j M Y')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <form method="post" data-confirm="Remove every reading imported from Apple Health? Readings you entered yourself are kept.">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_imported">
        <button class="btn btn-small btn-danger" type="submit">Remove all imported readings</button>
    </form>
</section>
<?php endif; ?>
<?php render_footer();
