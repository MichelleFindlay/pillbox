<?php
/*
 * Dose change history for one medicine: started, dose increases/reductions,
 * strength and timing changes, stops and restarts, plus notes added by hand.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$med = find_medication((int) get('id'), $uid) ?? not_found();
$self = 'medication_history.php?id=' . $med['id'];
$errors = [];

if (is_post()) {
    require_post();
    if (post('action') === 'delete') {
        q('DELETE FROM medication_changes WHERE id = ? AND medication_id = ?', [(int) post('change_id'), $med['id']]);
        flash('success', 'History entry removed.');
        redirect($self);
    }
    $kind = post('kind');
    $summary = post('summary');
    $date = post('effective_date');
    if (!isset(CHANGE_KINDS[$kind])) {
        $errors[] = 'Choose what kind of change it was.';
    }
    if ($summary === '' || mb_strlen($summary) > 500) {
        $errors[] = 'Describe the change (up to 500 characters).';
    }
    if (!parse_date($date)) {
        $errors[] = 'Enter the date of the change.';
    }
    if (!$errors) {
        record_med_change($uid, (int) $med['id'], $date, $kind, $summary, post('reason'));
        flash('success', 'Added to the history of ' . $med['name'] . '.');
        redirect($self);
    }
}

$changes = medication_changes((int) $med['id']);
$schedules = current_schedules((int) $med['id'], local_today()->format('Y-m-d'));

render_header($med['name'] . ' history', 'meds');
?>
<div class="page-head">
    <div>
        <h1><?= med_dot($med['colour']) ?> <?= h($med['name']) ?> <span class="muted"><?= h($med['strength']) ?></span></h1>
        <p class="muted small">Now: <?= h(medication_regimen($med, $schedules)) ?><?= $med['is_active'] ? '' : ' (stopped)' ?></p>
    </div>
    <span class="inline-actions">
        <a class="btn btn-small" href="<?= h(url('medication_edit.php?id=' . $med['id'])) ?>">Edit / change dose</a>
        <a class="btn btn-small" href="<?= h(url('medications.php')) ?>">All medicines</a>
    </span>
</div>

<section class="card">
    <h2>Dose history</h2>
    <?php if (!$changes): ?>
        <p class="muted">No changes recorded yet. Changes are recorded automatically when you edit the strength, amount or timing.
            You can also add earlier changes below.</p>
    <?php else: ?>
    <ol class="timeline">
        <?php foreach ($changes as $c): ?>
        <li class="tl-item tl-<?= h($c['kind']) ?>">
            <div class="tl-date"><?= h(date('j M Y', strtotime($c['effective_date']))) ?></div>
            <div class="tl-body">
                <span class="badge"><?= h(CHANGE_KINDS[$c['kind']] ?? $c['kind']) ?></span>
                <span><?= h($c['summary']) ?></span>
                <?php if ($c['reason']): ?><div class="muted small">Why: <?= h($c['reason']) ?></div><?php endif; ?>
            </div>
            <form method="post" data-confirm="Remove this entry from the history?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="change_id" value="<?= (int) $c['id'] ?>">
                <button class="btn-link danger small" type="submit">Remove</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Add an earlier change</h2>
    <p class="muted small">For changes from before you used Pillbox, e.g. "Started on 5mg" in 2022, or "Increased to 10mg" last spring.</p>
    <?= form_errors($errors) ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid-3">
            <label>Date <input type="date" name="effective_date" required value="<?= h(post('effective_date')) ?>"></label>
            <label>What happened
                <select name="kind">
                    <?php foreach (CHANGE_KINDS as $k => $label): ?><option value="<?= h($k) ?>"<?= selected(post('kind', 'increased') === $k) ?>><?= h($label === 'Changed' ? 'Changed to/from as needed' : $label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><span>Reason <span class="muted small">(optional)</span></span> <input name="reason" maxlength="255" value="<?= h(post('reason')) ?>"></label>
        </div>
        <label>Details <input name="summary" required maxlength="500" value="<?= h(post('summary')) ?>" placeholder="e.g. Increased from 5mg to 10mg a day"></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Add to history</button></span>
    </form>
</section>
<?php render_footer();
