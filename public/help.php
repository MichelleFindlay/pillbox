<?php
/*
 * Help now: crisis lines, the user's own people and professionals, and their safety
 * plan. One tap from every page (the lifebuoy in the header), and never behind the
 * Mind PIN, apart from the safety plan itself if the user chose to lock it.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
if (isset($user['_acting']) && !$user['_acting']['mind']) {
    $user = real_user(); // the safety plan is Mind content: show the carer's own help, not the owner's
}
$uid = (int) $user['id'];
seed_crisis_contacts($uid);
$contacts = mind_contacts($uid);
$plan = safety_plan($uid);
$planLocked = $user['mind_lock_plan'] && !mind_unlocked($user);
$toolkit = q('SELECT * FROM mind_toolkit WHERE user_id = ? ORDER BY id', [$uid])->fetchAll();
$fromRisk = get('checkin') === '1' || get('phq') === '1';

render_header('Help now', 'help');
?>
<div class="page-head">
    <h1>Help now</h1>
    <a class="btn btn-small" href="<?= h(url('mind_plan.php')) ?>">Edit plan and contacts</a>
</div>

<?php if ($fromRisk): ?>
<section class="card card-urgent" role="alert">
    <h2>Thank you for telling us</h2>
    <p>You don't have to cope with this on your own. Talking to someone can help, right now, whatever time it is.</p>
    <p><strong>If you might act on these thoughts, or you've harmed yourself, call 999 or go to A&amp;E now.</strong></p>
</section>
<?php endif; ?>

<section class="card">
    <h2>Talk to someone now</h2>
    <?php if (!$contacts['crisis']): ?>
    <p><strong>Call your local emergency number</strong> if your life is at risk. You can add crisis lines for your country in your plan.</p>
    <?php endif; ?>
    <ul class="plain contact-list">
        <?php foreach ($contacts['crisis'] as $c): ?>
        <li class="contact">
            <div><strong><?= h($c['name']) ?></strong><?= $c['detail'] ? '<br><span class="muted small">' . h($c['detail']) . '</span>' : '' ?></div>
            <div class="inline-actions"><?= contact_links($c) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($contacts['person'] || $contacts['professional']): ?>
<section class="card">
    <h2>My people and professionals</h2>
    <ul class="plain contact-list">
        <?php foreach ([...$contacts['professional'], ...$contacts['person']] as $c): ?>
        <li class="contact">
            <div><strong><?= h($c['name']) ?></strong><?= $c['detail'] ? '<br><span class="muted small">' . h($c['detail']) . '</span>' : '' ?>
                <?php if ($c['helps_with']): ?><br><?php foreach (explode(',', $c['helps_with']) as $k): ?><span class="amount-chip"><?= h(HELPS_WITH[$k] ?? $k) ?></span> <?php endforeach; ?><?php endif; ?></div>
            <div class="inline-actions"><?= contact_links($c) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <h2>My safety plan</h2>
    <?php if ($planLocked): ?>
        <p>Your safety plan is behind your Mind PIN. <a class="btn btn-small" href="<?= h(url('mind_unlock.php?return=help.php')) ?>">Unlock</a></p>
    <?php elseif (!array_filter($plan)): ?>
        <p class="muted">You haven't written a safety plan yet. It's best written on a calmer day, ideally with someone you trust or your care team,
            so it's ready when you need it.</p>
        <a class="btn" href="<?= h(url('mind_plan.php')) ?>">Write my safety plan</a>
    <?php else: ?>
        <?php foreach (SAFETY_PLAN_SECTIONS as $key => [$heading]): if (empty($plan[$key])) { continue; } ?>
        <h3><?= h($heading) ?></h3>
        <p class="plan-text"><?= nl2br(h($plan[$key])) ?></p>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Right now, you could</h2>
    <?php if ($toolkit && !$planLocked): ?>
    <ul class="toolkit-list">
        <?php foreach ($toolkit as $t): ?><li><?= h($t['item']) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <span class="inline-actions">
        <a class="btn btn-primary" href="<?= h(url('breathe.php#breathe')) ?>">Breathing exercise</a>
        <a class="btn" href="<?= h(url('breathe.php#ground')) ?>">5-4-3-2-1 grounding</a>
    </span>
</section>
<?php render_footer();
