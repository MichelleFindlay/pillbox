<?php
/*
 * Gratitude and small wins. A few are shown again after a low check-in.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);

if (is_post()) {
    require_post();
    if (post('action') === 'add' && ($text = trim(mb_substr(post('text'), 0, 500))) !== '') {
        q("INSERT INTO mind_notes (user_id, kind, text, created_at) VALUES (?, 'win', ?, UTC_TIMESTAMP())", [$uid, $text]);
        flash('success', 'Saved.');
    } elseif (post('action') === 'delete') {
        q("DELETE FROM mind_notes WHERE id = ? AND user_id = ? AND kind = 'win'", [(int) post('id'), $uid]);
    }
    redirect('mind_wins.php');
}

$wins = q("SELECT * FROM mind_notes WHERE user_id = ? AND kind = 'win' ORDER BY created_at DESC", [$uid])->fetchAll();

render_header('Wins and gratitude', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x2728; Wins and gratitude</h1>
        <p class="muted">Small positives count: a good cup of tea, getting out of bed, a kind message. A few are shown again on harder days.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
</div>

<section class="card">
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>Something good, however small <input name="text" maxlength="500" required autofocus placeholder="e.g. Went for a walk even though I didn't feel like it"></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
    <?php if (!$wins): ?><p class="muted">Nothing here yet.</p><?php endif; ?>
    <ul class="plain">
        <?php foreach ($wins as $w): ?>
        <li class="logline"><span><span class="muted small"><?= h(local_time($w['created_at'], 'j M Y')) ?></span> <?= h($w['text']) ?></span>
            <form method="post" data-confirm="Delete this?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                <button class="btn-link danger small" type="submit">Delete</button></form></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php render_footer();
