<?php
/*
 * Coping toolkit: the user's own list of things that help. Shown after a low check-in
 * and on the Help now page.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
const TOOLKIT_IDEAS = ['Go for a walk', 'Call or message a friend', 'Listen to music I love', 'Have a shower or bath', 'Make a hot drink',
    'Breathing exercise', 'Write down what I am feeling', 'Watch something comforting', 'Spend time with a pet', 'Get some fresh air',
    'Tidy one small thing', 'Do something creative'];

if (is_post()) {
    require_post();
    if (post('action') === 'add') {
        $item = trim(mb_substr(post('item'), 0, 200));
        if ($item !== '') {
            q('INSERT INTO mind_toolkit (user_id, item) VALUES (?, ?)', [$uid, $item]);
        }
    } elseif (post('action') === 'delete') {
        q('DELETE FROM mind_toolkit WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
    }
    redirect('mind_toolkit.php');
}

$toolkit = q('SELECT * FROM mind_toolkit WHERE user_id = ? ORDER BY id', [$uid])->fetchAll();
$have = array_map('mb_strtolower', array_column($toolkit, 'item'));

render_header('Coping toolkit', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F9F0; Coping toolkit</h1>
        <p class="muted">The things that help you. They're shown when you check in feeling low, and on the Help now page.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
</div>

<section class="card">
    <h2>What helps me</h2>
    <?php if (!$toolkit): ?><p class="muted">Nothing here yet. Add your own, or tap an idea below.</p><?php endif; ?>
    <ul class="plain">
        <?php foreach ($toolkit as $t): ?>
        <li class="logline"><span><?= h($t['item']) ?></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn-link danger" type="submit" aria-label="Remove <?= h($t['item']) ?>">Remove</button></form></li>
        <?php endforeach; ?>
    </ul>
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>Add <input name="item" maxlength="200" required placeholder="e.g. Text Jo, walk round the park"></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
    <?php if ($ideas = array_filter(TOOLKIT_IDEAS, fn ($i) => !in_array(mb_strtolower($i), $have, true))): ?>
    <p class="muted small">Ideas:</p>
    <div class="preset-list">
        <?php foreach ($ideas as $idea): ?>
        <form method="post" class="preset"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="item" value="<?= h($idea) ?>">
            <button class="btn btn-small" type="submit">+ <?= h($idea) ?></button></form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Exercises</h2>
    <span class="inline-actions">
        <a class="btn" href="<?= h(url('breathe.php#breathe')) ?>">Box breathing</a>
        <a class="btn" href="<?= h(url('breathe.php#ground')) ?>">5-4-3-2-1 grounding</a>
    </span>
</section>
<?php render_footer();
