<?php
/*
 * The communication passport (ADHD and autism), large and clear, to show on screen or print;
 * or, with ?show=prep, the assessment prep notes for printing.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
if (!share_area_allowed('neuro')) {
    redirect('care.php');
}
$p = nd_profile($uid);
$prep = get('show') === 'prep';

render_header($prep ? 'Assessment prep' : 'Communication passport', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=neuro#' . ($prep ? 'nd-prep' : 'nd-passport'))) ?>">&larr; Back</a>
</div>
<?php if ($prep): $notes = q('SELECT * FROM nd_prep WHERE user_id = ? ORDER BY category, created_at', [$uid])->fetchAll(); ?>
<article class="report">
    <h1>Assessment prep: <?= h($user['display_name']) ?></h1>
    <p class="muted">Examples noted in advance, to help on the day.</p>
    <?php $cat = null; foreach ($notes as $n): if ($n['category'] !== $cat): echo $cat ? '</ul>' : ''; $cat = $n['category']; ?><h2><?= h(ND_PREP_CATEGORIES[$cat] ?? $cat) ?></h2><ul><?php endif; ?>
        <li><?= nl2br(h($n['note'])) ?></li>
    <?php endforeach; echo $cat ? '</ul>' : ''; ?>
</article>
<?php else: ?>
<article class="passport">
    <h1><?= h($user['display_name']) ?><?= $user['pronouns'] ? ' <span>(' . h($user['pronouns']) . ')</span>' : '' ?></h1>
    <p class="passport-lead">How to communicate with me</p>
    <?php foreach (ND_PASSPORT_FIELDS as $key => [$label]): if (empty($p['passport'][$key])) { continue; } ?>
    <section><h2><?= h($label) ?></h2><p><?= nl2br(h($p['passport'][$key])) ?></p></section>
    <?php endforeach; ?>
    <?php if (!array_filter($p['passport'])): ?><p>Nothing filled in yet. <a href="<?= h(url('care.php?area=neuro#nd-passport')) ?>">Fill in your passport</a>.</p><?php endif; ?>
</article>
<?php endif; ?>
<?php render_footer();
