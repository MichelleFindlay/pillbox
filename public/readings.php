<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$metrics = enabled_metrics($user);
$allMetrics = user_metric_definitions($uid);

$key = get('metric', (string) array_key_first($metrics));
if (!isset($allMetrics[$key])) {
    $key = (string) array_key_first($metrics);
}
$def = $allMetrics[$key] ?? null;
$range = in_array(get('range'), ['7', '30', '90', '365'], true) ? (int) get('range') : 30;
$errors = [];
$input = [];

if (is_post() && $def) {
    require_post();
    if (post('action') === 'delete') {
        q('DELETE FROM health_readings WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
        flash('success', 'Reading deleted.');
        redirect('readings.php?metric=' . urlencode($key) . '&range=' . $range);
    }

    $input = $_POST;
    [$values, $errors] = validate_reading($def, $_POST);
    $recordedAt = local_to_utc(post('recorded_at'));
    if ($recordedAt === null) {
        $errors[] = 'Enter when the reading was taken.';
    } elseif ($recordedAt > gmdate('Y-m-d H:i:s', time() + 300)) {
        $errors[] = 'The reading time is in the future.';
    }
    $context = post('context');
    if ($context !== '' && !in_array($context, $def['contexts'] ?? [], true)) {
        $context = mb_substr($context, 0, 60);
    }
    $notes = mb_substr(post('notes'), 0, 500);
    if (!empty($def['notes_required']) && $notes === '') {
        $errors[] = 'Describe the symptom in the notes.';
    }
    if (!$errors) {
        q(
            'INSERT INTO health_readings (user_id, metric, value1, value2, value3, context, notes, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $key, $values[0], $values[1], $values[2], $context ?: null, $notes ?: null, $recordedAt]
        );
        if ($key === 'blood_pressure' && ($bpAdvice = bp_advice((int) $values[0], (int) $values[1]))) {
            flash('error', 'Saved. ' . $bpAdvice[1]);
        } elseif ($key === 'blood_ketones') {
            [$level, $advice] = ketone_advice((float) $values[0]);
            flash($level === 'ok' ? 'success' : 'error', 'Saved. ' . $advice);
        } elseif ($key === 'blood_glucose' && (float) $values[0] < 4.0) {
            flash('error', 'Saved. Under 4 is a hypo: treat it now with 15 to 20 g of fast-acting sugar, then check again in 15 minutes. The hypo guide has a timer.');
            redirect('hypo.php');
        } else {
            flash('success', $def['label'] . ' saved.');
        }
        redirect('readings.php?metric=' . urlencode($key) . '&range=' . $range);
    }
}

$fromTs = strtotime("-$range days");
$readings = $def ? q(
    'SELECT * FROM health_readings WHERE user_id = ? AND metric = ? AND recorded_at >= ? ORDER BY recorded_at DESC',
    [$uid, $key, gmdate('Y-m-d H:i:s', $fromTs)]
)->fetchAll() : [];

render_header('Readings', 'readings');
?>
<div class="page-head">
    <h1>Health readings <?= help_link('readings', '', 'Readings') ?></h1>
    <span class="inline-actions">
        <a class="btn btn-small" href="<?= h(url('connections.php')) ?>">Connect apps and devices</a>
        <a class="btn btn-small" href="<?= h(url('account.php#metrics')) ?>">Choose what to track</a>
    </span>
</div>

<?php if (!$metrics): ?>
<section class="card"><p>You aren't tracking any readings. <a href="<?= h(url('account.php#metrics')) ?>">Choose some in your settings.</a></p></section>
<?php render_footer(); exit; endif; ?>

<nav class="chips" aria-label="Reading type">
    <?php foreach ($metrics as $k => $m): ?>
    <a class="chip" href="<?= h(url('readings.php?metric=' . urlencode($k) . '&range=' . $range)) ?>"<?= $k === $key ? ' aria-current="page"' : '' ?>><?= h($m['label']) ?></a>
    <?php endforeach; ?>
</nav>

<div class="readings-layout">
<section class="card">
    <h2>Record <?= h(mb_strtolower($def['label'])) ?></h2>
    <?php if (!empty($def['help'])): ?><p class="muted small"><?= h($def['help']) ?></p><?php endif; ?>
    <?= form_errors($errors) ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <div class="grid-<?= min(3, count($def['fields'])) ?>">
        <?php foreach ($def['fields'] as $i => $field): $name = 'value' . ($i + 1); ?>
            <label><span><?= h($field['label']) ?><?= $field['unit'] !== '' ? ' <span class="muted">(' . h($field['unit']) . ')</span>' : '' ?><?= $field['required'] ? '' : ' <span class="muted small">optional</span>' ?></span>
                <input type="number" inputmode="decimal" name="<?= $name ?>" step="<?= h($field['step']) ?>" min="<?= h($field['min']) ?>" max="<?= h($field['max']) ?>"
                    <?= $field['required'] ? 'required' : '' ?> value="<?= h($input[$name] ?? '') ?>"<?= $i === 0 ? ' autofocus' : '' ?>>
            </label>
        <?php endforeach; ?>
        </div>
        <div class="grid-2">
            <label>When <input type="datetime-local" name="recorded_at" required value="<?= h($input['recorded_at'] ?? (new DateTimeImmutable('now', user_tz()))->format('Y-m-d\TH:i')) ?>"></label>
            <?php if (!empty($def['contexts'])): ?>
            <label>Context
                <select name="context">
                    <option value="">—</option>
                    <?php foreach ($def['contexts'] as $c): ?><option<?= selected(($input['context'] ?? '') === $c) ?>><?= h($c) ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
        </div>
        <label><span>Notes<?= !empty($def['notes_required']) ? '' : ' <span class="muted small">optional</span>' ?></span>
            <textarea name="notes" rows="2" maxlength="500"<?= !empty($def['notes_required']) ? ' required' : '' ?>><?= h($input['notes'] ?? '') ?></textarea>
        </label>
        <button class="btn btn-primary" type="submit">Save reading</button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= h($def['label']) ?> trend</h2>
        <nav class="segmented" aria-label="Time range">
            <?php foreach ([7 => '7d', 30 => '30d', 90 => '90d', 365 => '1y'] as $r => $label): ?>
            <a href="<?= h(url('readings.php?metric=' . urlencode($key) . '&range=' . $r)) ?>"<?= $r === $range ? ' aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php
    $series = [];
    foreach ($def['fields'] as $i => $field) {
        $points = [];
        foreach ($readings as $r) {
            if ($r['value' . ($i + 1)] !== null) {
                $points[] = [strtotime($r['recorded_at'] . ' UTC'), (float) $r['value' . ($i + 1)]];
            }
        }
        $series[] = ['label' => $field['label'], 'unit' => $field['unit'], 'points' => $points];
    }
    // Pulse is on a different scale from blood pressure; keep one axis and drop it from the chart (it's still in the table).
    if ($key === 'blood_pressure') {
        $series = array_slice($series, 0, 2);
    }
    echo svg_line_chart($series, $fromTs, time(), $def['label'] . ' over the last ' . $range . ' days');

    $stats = metric_stats($def, $readings);
    if ($stats): ?>
    <dl class="stats">
        <?php foreach ($stats as $st): ?>
        <div><dt><?= h($st['label']) ?></dt><dd>avg <?= h(num(round($st['avg'], 1))) ?> · range <?= h(num($st['min'])) ?>–<?= h(num($st['max'])) ?> <?= h($st['unit']) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php endif; ?>
</section>
</div>

<section class="card">
    <h2>Readings (last <?= $range ?> days)</h2>
    <?php if (!$readings): ?>
        <p class="muted">No readings yet.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>When</th><th>Reading</th><th>Context</th><th>Notes</th><th><span class="sr">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($readings as $r): ?>
            <tr>
                <td><?= h(local_time($r['recorded_at'], 'D j M, H:i')) ?></td>
                <td><strong><?= h(format_reading($def, $r)) ?></strong></td>
                <td><?= h($r['context']) ?></td>
                <td><?= h($r['notes']) ?><?= $r['source'] ? ' <span class="badge">' . h(reading_source_label($r['source'])) . '</span>' : '' ?></td>
                <td>
                    <form method="post" data-confirm="Delete this reading?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn-link danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php render_footer();
