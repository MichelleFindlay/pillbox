<?php
/*
 * Journal: free writing (optionally from a prompt) and CBT thought records.
 * mind_journal.php lists entries; ?new=journal|thought or ?id=<n> shows the editor.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
if (isset($user['_acting']) && !$user['_acting']['perms']['journal']) {
    flash('error', 'The journal is private: it has not been shared with you.');
    redirect('mind.php');
}

$entry = get('id') !== '' || post('id') !== ''
    ? q('SELECT * FROM mind_journal WHERE id = ? AND user_id = ?', [(int) (post('id') ?: get('id')), $uid])->fetch() ?: null
    : null;
if ((get('id') !== '' || post('id') !== '') && !$entry) {
    not_found();
}
$kind = $entry['kind'] ?? (in_array(get('new') ?: post('kind'), ['journal', 'thought'], true) ? (get('new') ?: post('kind')) : null);

if (is_post()) {
    require_post();
    if (post('action') === 'delete' && $entry) {
        q('DELETE FROM mind_journal WHERE id = ?', [$entry['id']]);
        flash('success', 'Entry deleted.');
        redirect('mind_journal.php');
    }
    if (post('action') === 'save' && $kind) {
        $prompt = trim(mb_substr(post('prompt'), 0, 255)) ?: null;
        $body = $kind === 'journal' ? trim(mb_substr(post('body'), 0, 50000)) : null;
        $fields = null;
        if ($kind === 'thought') {
            $fields = [];
            foreach (array_keys(THOUGHT_FIELDS) as $f) {
                $fields[$f] = trim(mb_substr((string) ($_POST['f'][$f] ?? ''), 0, 5000));
            }
        }
        if (($kind === 'journal' && $body === '') || ($kind === 'thought' && !array_filter($fields))) {
            flash('error', 'Write something first.');
            redirect('mind_journal.php?' . ($entry ? 'id=' . $entry['id'] : 'new=' . $kind));
        }
        $json = $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null;
        if ($entry) {
            q('UPDATE mind_journal SET prompt = ?, body = ?, fields = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$prompt, $body, $json, $entry['id']]);
        } else {
            q('INSERT INTO mind_journal (user_id, kind, prompt, body, fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$uid, $kind, $prompt, $body, $json]);
        }
        flash('success', $kind === 'thought' ? 'Thought record saved.' : 'Journal entry saved.');
        redirect('mind_journal.php');
    }
}

render_header('Journal', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F4D3; Journal</h1>
        <p class="muted">Just for you. Nothing here goes in reports unless you choose to include it.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url($kind ? 'mind_journal.php' : 'mind.php')) ?>">&larr; <?= $kind ? 'Journal' : 'Mind' ?></a>
</div>

<?php if ($kind === 'journal'):
    $prompt = $entry['prompt'] ?? (get('prompt') !== '' && isset(JOURNAL_PROMPTS[(int) get('prompt')]) ? JOURNAL_PROMPTS[(int) get('prompt')] : null); ?>
<form method="post" class="card stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="kind" value="journal">
    <?php if ($entry): ?><input type="hidden" name="id" value="<?= (int) $entry['id'] ?>"><?php endif; ?>
    <h2><?= $entry ? h(local_time($entry['created_at'], 'l j F Y, H:i')) : 'New entry' ?></h2>
    <?php if ($prompt): ?><p class="journal-prompt"><?= h($prompt) ?></p><input type="hidden" name="prompt" value="<?= h($prompt) ?>"><?php endif; ?>
    <?php if (!$entry && !$prompt): ?>
    <p class="muted small">Need a starting point? <?php foreach (JOURNAL_PROMPTS as $i => $p): ?><a class="chip chip-inline" href="<?= h(url('mind_journal.php?new=journal&prompt=' . $i)) ?>"><?= h($p) ?></a> <?php endforeach; ?></p>
    <?php endif; ?>
    <label><span class="sr">Entry</span><textarea name="body" rows="12" maxlength="50000" required autofocus><?= h($entry['body'] ?? '') ?></textarea></label>
    <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
</form>

<?php elseif ($kind === 'thought'):
    $f = $entry ? (json_decode((string) $entry['fields'], true) ?: []) : []; ?>
<form method="post" class="card stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="kind" value="thought">
    <?php if ($entry): ?><input type="hidden" name="id" value="<?= (int) $entry['id'] ?>"><?php endif; ?>
    <h2><?= $entry ? 'Thought record, ' . h(local_time($entry['created_at'], 'j F Y')) : 'New thought record' ?></h2>
    <p class="muted small">Work through it step by step. You don't have to fill in every box.</p>
    <?php foreach (THOUGHT_FIELDS as $key => [$label, $help]): ?>
    <label><span><strong><?= h($label) ?></strong><br><span class="muted small"><?= h($help) ?></span></span>
        <textarea name="f[<?= h($key) ?>]" rows="<?= in_array($key, ['for', 'against'], true) ? 4 : 2 ?>" maxlength="5000"><?= h($f[$key] ?? '') ?></textarea></label>
    <?php endforeach; ?>
    <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
</form>
<?php endif; ?>

<?php if ($entry): ?>
<form method="post" class="inline-actions" data-confirm="Delete this entry? This can't be undone.">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
    <button class="btn btn-small btn-danger" type="submit">Delete entry</button>
</form>
<?php endif; ?>

<?php if (!$kind):
    $entries = q('SELECT * FROM mind_journal WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$uid])->fetchAll(); ?>
<div class="mind-links">
    <a class="care-tile" href="<?= h(url('mind_journal.php?new=journal')) ?>"><span class="care-tile-title">&#x270F;&#xFE0F; Write</span><span class="muted small">Free writing, with a prompt if you like</span></a>
    <a class="care-tile" href="<?= h(url('mind_journal.php?new=thought')) ?>"><span class="care-tile-title">&#x1F914; Thought record</span><span class="muted small">Look at a difficult thought from all sides</span></a>
</div>
<section class="card">
    <h2>Entries</h2>
    <?php if (!$entries): ?><p class="muted">Nothing written yet.</p><?php endif; ?>
    <ul class="plain">
        <?php foreach ($entries as $e):
            $preview = $e['kind'] === 'thought' ? ((json_decode((string) $e['fields'], true) ?: [])['thought'] ?? '') : (string) $e['body']; ?>
        <li class="logline"><a href="<?= h(url('mind_journal.php?id=' . $e['id'])) ?>"><strong><?= h(local_time($e['created_at'], 'D j M Y')) ?></strong>
            <?= $e['kind'] === 'thought' ? '<span class="amount-chip">Thought record</span>' : '' ?></a>
            <span class="muted small journal-preview"><?= h(mb_strimwidth($e['prompt'] ? $e['prompt'] . ' ' . $preview : $preview, 0, 120, '…')) ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php render_footer();
