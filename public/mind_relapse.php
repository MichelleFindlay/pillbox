<?php
/*
 * Relapse prevention plan: what I'm like when well, early warning signs and what to
 * do, and crisis signs. Shown again on the Mind page after a few low days.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$keys = array_merge(...array_map(fn ($stage) => array_keys($stage[1]), array_values(RELAPSE_PLAN)));

if (is_post()) {
    require_post();
    save_plan_text($uid, $keys, (array) ($_POST['plan'] ?? []));
    flash('success', 'Plan saved.');
    redirect('mind_relapse.php');
}
$plan = plan_text($uid, $keys);

render_header('Staying well plan', 'mind');
?>
<div class="page-head no-print">
    <div>
        <h1>&#x1F6A6; Staying well plan</h1>
        <p class="muted">A relapse prevention plan: notice early when things are slipping, and know what to do at each stage. Worth writing with your care team.</p>
    </div>
    <span class="inline-actions">
        <button class="btn btn-small" type="button" data-print>Print</button>
        <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
    </span>
</div>
<h1 class="print-only">Staying well plan: <?= h($user['display_name']) ?></h1>

<form method="post" class="stack">
    <?= csrf_field() ?>
    <?php foreach (RELAPSE_PLAN as $stage => [$heading, $fields]): ?>
    <section class="card stage stage-<?= h($stage) ?>">
        <h2><?= h($heading) ?></h2>
        <?php foreach ($fields as $key => $label): ?>
        <label><span><strong><?= h($label) ?></strong></span>
            <textarea class="no-print" name="plan[<?= h($key) ?>]" rows="3" maxlength="5000"><?= h($plan[$key] ?? '') ?></textarea>
            <span class="print-only"><?= nl2br(h($plan[$key] ?? '')) ?></span></label>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
    <span class="inline-actions no-print"><button class="btn btn-primary" type="submit">Save plan</button>
        <a class="btn" href="<?= h(url('mind_plan.php')) ?>">Safety plan</a></span>
</form>
<?php render_footer();
