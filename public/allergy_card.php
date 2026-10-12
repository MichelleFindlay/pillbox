<?php
/*
 * An allergy card, large and clear, to show on screen or print (Allergies); or, with ?show=food,
 * a card to hand to restaurant or kitchen staff.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
if (!share_area_allowed('allergies')) {
    redirect('care.php');
}
$allergies = array_values(array_filter(user_allergies($uid), fn ($a) => $a['status'] === 'current'));
$profile = allergy_profile($uid);
$food = get('show') === 'food';
$foodAllergies = array_values(array_filter($allergies, fn ($a) => $a['category'] === 'food'));
$serious = array_filter($food ? $foodAllergies : $allergies, fn ($a) => in_array($a['severity'], ['severe', 'anaphylaxis'], true));

render_header($food ? 'Food allergy card' : 'Allergy card', 'care');
?>
<div class="filters no-print card">
    <button class="btn btn-primary" type="button" data-print>Print / save as PDF</button>
    <a class="btn" href="<?= h(url('care.php?area=allergies#al-list')) ?>">&larr; Back</a>
</div>
<?php if ($food): ?>
<article class="passport">
    <h1>I have a food allergy</h1>
    <p class="passport-lead">Please make sure my food doesn't contain any of these:</p>
    <section><ul class="allergy-card-list"><?php foreach ($foodAllergies as $a): ?><li><strong><?= h($a['allergen']) ?></strong></li><?php endforeach; ?></ul></section>
    <section><p>That includes traces in sauces, dressings, oils, garnishes and stock, and anything cooked in the same oil or prepared with the same utensils or surfaces.
        If you're not sure, please tell me: I'd rather choose something else.</p>
        <?php if ($serious): ?><p><strong>My reactions can be serious, even to a tiny amount.</strong></p><?php endif; ?>
        <p>Thank you!</p></section>
    <?php if (!$foodAllergies): ?><p>No food allergies recorded. <a href="<?= h(url('care.php?area=allergies#al-list')) ?>">Add them</a>.</p><?php endif; ?>
</article>
<?php else: ?>
<article class="passport">
    <h1>Allergy alert: <?= h($user['display_name']) ?></h1>
    <?php if ($allergies): ?>
    <p class="passport-lead">I am allergic to:</p>
    <section><ul class="allergy-card-list"><?php foreach ($allergies as $a): ?>
        <li><strong><?= h($a['allergen']) ?></strong><?= $a['severity'] ? ' (' . h(mb_strtolower(ALLERGY_SEVERITY[$a['severity']])) . ')' : '' ?><?= $a['kind'] !== 'allergy' ? ' &ndash; ' . h(mb_strtolower(ALLERGY_KINDS[$a['kind']])) : '' ?>
            <?php if ($a['reactions']): ?><span><?= h(implode(', ', array_map(fn ($r) => mb_strtolower(ALLERGY_REACTIONS[$r] ?? $r), $a['reactions']))) ?></span><?php endif; ?></li>
    <?php endforeach; ?></ul></section>
    <?php elseif ($profile['none_known']): ?>
    <p class="passport-lead">No known allergies.</p>
    <?php else: ?>
    <p>No allergies recorded. <a href="<?= h(url('care.php?area=allergies#al-list')) ?>">Add them</a>.</p>
    <?php endif; ?>
    <?php if ($profile['aai']): ?>
    <section><h2>Adrenaline auto-injectors</h2><p>I carry<?= $profile['aai_brand'] ? ' ' . h($profile['aai_brand']) : ' adrenaline auto-injectors' ?><?= $profile['aai_where'] ? ': ' . h($profile['aai_where']) : '' ?>.</p></section>
    <?php endif; ?>
    <?php if ($serious || $profile['aai']): ?>
    <section><h2>If I have a severe reaction</h2>
        <ol>
            <li>Use my adrenaline auto-injector, into the outer thigh.</li>
            <li>Call 999 and say "anaphylaxis".</li>
            <li>Lie me down with my legs raised (or sitting up if I'm struggling to breathe). Don't let me stand up.</li>
            <li>If I'm no better after 5 minutes, use the second auto-injector.</li>
        </ol></section>
    <?php endif; ?>
</article>
<?php endif; ?>
<?php render_footer();
