<?php
/*
 * Advance statement: the user's wishes for their care if they become unwell, to
 * print and share with their GP and care team.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$keys = array_keys(ADVANCE_STATEMENT);

if (is_post()) {
    require_post();
    save_plan_text($uid, $keys, (array) ($_POST['plan'] ?? []));
    flash('success', 'Advance statement saved. Print it and give copies to the people listed.');
    redirect('mind_advance.php');
}
$plan = plan_text($uid, $keys);
$updated = q('SELECT MAX(updated_at) FROM mind_plan WHERE user_id = ? AND section LIKE ?', [$uid, 'adv\_%'])->fetchColumn();

render_header('Advance statement', 'mind');
?>
<div class="page-head no-print">
    <div>
        <h1>&#x1F4DD; Advance statement</h1>
        <p class="muted">Your wishes for your care if you become too unwell to say what you want. Write it while you're well, ideally with your care team.</p>
    </div>
    <span class="inline-actions">
        <button class="btn btn-small" type="button" data-print>Print / save as PDF</button>
        <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
    </span>
</div>

<p class="flash flash-warning no-print">An advance statement isn't legally binding, but the people caring for you must take it into account. To refuse a specific
    treatment in a legally binding way you need an <strong>advance decision</strong>; ask your GP or care team. If you're detained under the Mental Health Act,
    some treatment can be given without your consent.</p>

<article class="report">
    <header class="report-head print-only">
        <div>
            <h1>Advance statement</h1>
            <p><strong><?= h($user['display_name']) ?></strong><?= $user['date_of_birth'] ? ' · born ' . h((new DateTimeImmutable($user['date_of_birth']))->format('j M Y')) : '' ?></p>
            <?php if ($updated): ?><p class="muted">Last updated <?= h(local_time($updated, 'j F Y')) ?></p><?php endif; ?>
        </div>
    </header>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <?php foreach (ADVANCE_STATEMENT as $key => [$heading, $prompt]): ?>
        <section class="card<?= empty($plan[$key]) ? ' print-hide-empty' : '' ?>">
            <label><span><strong><?= h($heading) ?></strong><?= $prompt ? '<br><span class="muted small no-print">' . h($prompt) . '</span>' : '' ?></span>
                <textarea class="no-print" name="plan[<?= h($key) ?>]" rows="3" maxlength="5000"><?= h($plan[$key] ?? '') ?></textarea>
                <span class="print-only"><?= nl2br(h($plan[$key] ?? '')) ?></span></label>
        </section>
        <?php endforeach; ?>
        <span class="inline-actions no-print"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
    <div class="print-only signature-lines">
        <p>Signed ______________________________ Date ______________</p>
        <p>Witness ______________________________ Date ______________</p>
    </div>
</article>
<?php render_footer();
