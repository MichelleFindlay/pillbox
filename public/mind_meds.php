<?php
/*
 * Mental health medication: the medicines marked as such, their latest change, and
 * notes on side effects and "how I've felt since starting / changing the dose".
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$today = local_today();

if (is_post()) {
    require_post();
    $med = find_medication((int) post('medication_id'), $uid) ?? not_found();
    switch (post('action')) {
        case 'mark':
            q('UPDATE medications SET mental_health = ? WHERE id = ?', [post('on') === '1' ? 1 : 0, $med['id']]);
            break;
        case 'note':
            $note = trim(mb_substr(post('note'), 0, 1000));
            $kind = post('kind') === 'side_effect' ? 'side_effect' : 'feeling';
            $severity = $kind === 'side_effect' && post('severity') !== '' ? max(0, min(10, (int) post('severity'))) : null;
            if ($note !== '') {
                q('INSERT INTO med_notes (user_id, medication_id, kind, note, severity, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$uid, $med['id'], $kind, $note, $severity]);
                flash('success', 'Note saved. It will be suggested at your next therapy or review appointment.');
            }
            break;
        case 'delete_note':
            // Only a note on this (visible) medicine: never one on a private medicine hidden from someone with shared access.
            q('DELETE FROM med_notes WHERE id = ? AND user_id = ? AND medication_id = ?', [(int) post('note_id'), $uid, $med['id']]);
            break;
    }
    redirect('mind_meds.php#med-' . $med['id']);
}

$meds = mind_medications($uid);
$others = array_filter(user_medications($uid), fn ($m) => !$m['mental_health']);
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));

render_header('Mental health medication', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F48A; Mental health medication</h1>
        <p class="muted">Notes on how you're getting on, ready for reviews. Doses and stock are on the Meds tab as usual.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('mind_care.php')) ?>">&larr; Therapy and care</a>
</div>

<?php foreach ($meds as $m):
    [$changedOn, $changeText] = $m['last_change'] ? explode('|', $m['last_change'], 2) : [null, null];
    $notes = med_notes($uid, (int) $m['id']); ?>
<section class="card" id="med-<?= (int) $m['id'] ?>">
    <div class="card-head">
        <h2><?= med_dot($m['colour']) ?> <?= h($m['name']) ?> <span class="muted"><?= h($m['strength']) ?></span></h2>
        <a class="btn btn-small" href="<?= h(url('medication_history.php?id=' . $m['id'])) ?>">History</a>
    </div>
    <p class="small"><?= h(implode('; ', schedule_lines($schedules[$m['id']] ?? [], $m['dose_unit'])) ?: ($m['as_needed'] ? 'As needed' : '')) ?></p>
    <?php if ($changedOn): $days = (new DateTimeImmutable($changedOn))->diff($today)->days; ?>
    <p class="small"><strong><?= h($changeText) ?></strong> <span class="muted">on <?= h(date('j M Y', strtotime($changedOn))) ?>
        (<?= $days === 0 ? 'today' : ($days < 14 ? "$days days ago" : round($days / 7) . ' weeks ago') ?>)</span></p>
    <?php endif; ?>
    <details class="q-edit"<?= !$notes ? ' open' : '' ?>>
        <summary>Add a note</summary>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>">
            <div class="repeat-choice">
                <label class="check"><input type="radio" name="kind" value="feeling" checked id="k-f-<?= (int) $m['id'] ?>"> How I've felt since starting or changing it</label>
                <label class="check"><input type="radio" name="kind" value="side_effect" id="k-s-<?= (int) $m['id'] ?>"> A side effect</label>
            </div>
            <label><span class="sr">Note</span><textarea name="note" rows="2" maxlength="1000" required placeholder="e.g. Sleeping better, but feel flat in the mornings"></textarea></label>
            <label class="narrow" data-show-when="k-s-<?= (int) $m['id'] ?>">How bad <span class="muted small">(0 to 10)</span>
                <select name="severity"><option value="">&ndash;</option><?php for ($i = 0; $i <= 10; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></label>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save note</button></span>
        </form>
    </details>
    <?php if ($notes): ?>
    <ul class="plain">
        <?php foreach ($notes as $n): ?>
        <li class="logline"><span><strong><?= h(local_time($n['created_at'], 'j M Y')) ?></strong>
            <span class="amount-chip"><?= $n['kind'] === 'side_effect' ? 'Side effect' . ($n['severity'] !== null ? ' ' . (int) $n['severity'] . '/10' : '') : 'How I feel' ?></span>
            <?= h($n['note']) ?></span>
            <form method="post" data-confirm="Delete this note?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_note"><input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                <button class="btn-link danger small" type="submit">Delete</button></form></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="mark"><input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="on" value="0">
        <button class="btn-link small" type="submit">Not a mental health medicine</button></form>
</section>
<?php endforeach; ?>

<section class="card">
    <h2><?= $meds ? 'Other medicines' : 'Which of your medicines are for your mental health?' ?></h2>
    <?php if (!$others): ?>
    <p class="muted"><?= $meds ? 'None.' : 'You have no current medicines in Pillbox.' ?> <a href="<?= h(url('medication_edit.php')) ?>">Add a medication</a></p>
    <?php else: ?>
    <ul class="plain">
        <?php foreach ($others as $m): ?>
        <li class="logline"><span><?= med_dot($m['colour']) ?> <?= h($m['name']) ?> <span class="muted small"><?= h($m['strength']) ?></span></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark"><input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="on" value="1">
                <button class="btn btn-small" type="submit">+ Mental health medicine</button></form></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php render_footer();
