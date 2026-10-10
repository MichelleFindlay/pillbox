<?php
/*
 * Safety plan (NHS / Stanley-Brown format) and contacts: crisis lines (pre-filled for
 * the UK, editable), professionals and the people the user can turn to.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
seed_crisis_contacts($uid);
const CONTACT_KINDS = ['crisis' => 'Crisis line', 'professional' => 'Professional or service', 'person' => 'Someone I know'];

if (is_post()) {
    require_post();
    switch (post('action')) {
        case 'plan':
            foreach (array_keys(SAFETY_PLAN_SECTIONS) as $key) {
                $text = trim(mb_substr((string) ($_POST['plan'][$key] ?? ''), 0, 5000));
                if ($text === '') {
                    q('DELETE FROM mind_plan WHERE user_id = ? AND section = ?', [$uid, $key]);
                } else {
                    q('INSERT INTO mind_plan (user_id, section, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)', [$uid, $key, $text]);
                }
            }
            flash('success', 'Safety plan saved. It is on the Help now page, one tap from anywhere.');
            redirect('mind_plan.php');
        case 'contact':
            $kind = isset(CONTACT_KINDS[post('kind')]) ? post('kind') : 'person';
            $name = trim(mb_substr(post('name'), 0, 100));
            $phone = trim(mb_substr(post('phone'), 0, 40)) ?: null;
            $textTo = trim(mb_substr(post('text_to'), 0, 40)) ?: null;
            if ($name === '' || (!$phone && !$textTo && $kind === 'crisis')) {
                flash('error', $name === '' ? 'Give the contact a name.' : 'Add a number to call or text.');
                redirect('mind_plan.php#contacts');
            }
            $helps = implode(',', array_intersect(array_keys(HELPS_WITH), (array) ($_POST['helps_with'] ?? []))) ?: null;
            $every = (int) post('reach_out_days');
            $values = [$kind, $name, trim(mb_substr(post('detail'), 0, 255)) ?: null, $phone, $textTo, trim(mb_substr(post('text_body'), 0, 40)) ?: null,
                $helps, $every >= 1 && $every <= 365 ? $every : null];
            if (post('id') !== '') {
                q('UPDATE mind_contacts SET kind = ?, name = ?, detail = ?, phone = ?, text_to = ?, text_body = ?, helps_with = ?, reach_out_days = ? WHERE id = ? AND user_id = ?', [...$values, (int) post('id'), $uid]);
            } else {
                q('INSERT INTO mind_contacts (kind, name, detail, phone, text_to, text_body, helps_with, reach_out_days, user_id, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100)', [...$values, $uid]);
            }
            flash('success', $name . ' saved.');
            redirect('mind_plan.php#contacts');
        case 'delete_contact':
            q('DELETE FROM mind_contacts WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            flash('success', 'Contact removed.');
            redirect('mind_plan.php#contacts');
    }
}

$plan = safety_plan($uid);
$contacts = mind_contacts($uid);

/** The add / change contact fields. */
function contact_fields(array $c): void
{
    ?>
    <div class="grid-2">
        <label>Name <input name="name" maxlength="100" required value="<?= h($c['name'] ?? '') ?>" placeholder="e.g. Sam (sister), Crisis team"></label>
        <label>Type
            <select name="kind"><?php foreach (CONTACT_KINDS as $k => $l): ?><option value="<?= h($k) ?>"<?= selected(($c['kind'] ?? 'person') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Phone <span class="muted small">(optional)</span> <input type="tel" name="phone" maxlength="40" value="<?= h($c['phone'] ?? '') ?>"></label>
        <label><span>What for <span class="muted small">(optional)</span></span> <input name="detail" maxlength="255" value="<?= h($c['detail'] ?? '') ?>" placeholder="e.g. practical help, to talk, distraction"></label>
        <label><span>Text number <span class="muted small">(optional)</span></span> <input type="tel" name="text_to" maxlength="40" value="<?= h($c['text_to'] ?? '') ?>"></label>
        <label><span>Message to text <span class="muted small">(optional)</span></span> <input name="text_body" maxlength="40" value="<?= h($c['text_body'] ?? '') ?>" placeholder="e.g. SHOUT"></label>
        <label><span>Remind me to get in touch every <span class="muted small">(days, optional)</span></span>
            <input type="number" name="reach_out_days" min="1" max="365" inputmode="numeric" value="<?= h((string) ($c['reach_out_days'] ?? '')) ?>" placeholder="e.g. 14"></label>
    </div>
    <fieldset>
        <legend>Good for <span class="muted small">(optional)</span></legend>
        <div class="checks">
            <?php $helps = explode(',', (string) ($c['helps_with'] ?? '')); foreach (HELPS_WITH as $k => $l): ?>
            <label class="check"><input type="checkbox" name="helps_with[]" value="<?= h($k) ?>"<?= checked(in_array($k, $helps, true)) ?>> <?= h($l) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php
}

render_header('Safety plan', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F6DF; Safety plan</h1>
        <p class="muted">Write it on a calmer day, ideally with someone you trust or your care team. It's shown on the Help now page, one tap from anywhere.</p>
    </div>
    <span class="inline-actions">
        <a class="btn btn-small btn-help" href="<?= h(url('help.php')) ?>">View on Help now</a>
        <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
    </span>
</div>

<form method="post" class="card stack" id="plan">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="plan">
    <?php foreach (SAFETY_PLAN_SECTIONS as $key => [$heading, $prompt]): ?>
    <label><span><strong><?= h($heading) ?></strong><br><span class="muted small"><?= h($prompt) ?></span></span>
        <textarea name="plan[<?= h($key) ?>]" rows="3" maxlength="5000"><?= h($plan[$key] ?? '') ?></textarea></label>
    <?php endforeach; ?>
    <span class="inline-actions"><button class="btn btn-primary" type="submit">Save safety plan</button></span>
</form>

<section class="card" id="contacts">
    <h2>Contacts</h2>
    <p class="muted small">The crisis lines are UK numbers. If you live elsewhere, change them to your country's.</p>
    <?php foreach (CONTACT_KINDS as $kind => $kindLabel): if (!$contacts[$kind]) { continue; } ?>
    <h3><?= h($kindLabel === 'Crisis line' ? 'Crisis lines' : ($kind === 'person' ? 'People I know' : 'Professionals and services')) ?></h3>
    <ul class="plain">
        <?php foreach ($contacts[$kind] as $c): ?>
        <li class="care-task">
            <div class="logline">
                <span><strong><?= h($c['name']) ?></strong> <span class="muted small"><?= h(implode(' · ', array_filter([$c['phone'], $c['text_to'] ? 'text ' . trim($c['text_body'] . ' to ' . $c['text_to']) : null,
                    $c['helps_with'] ? 'good for ' . mb_strtolower(implode(', ', array_map(fn ($k) => HELPS_WITH[$k] ?? $k, explode(',', $c['helps_with'])))) : null,
                    $c['reach_out_days'] ? 'reminder every ' . $c['reach_out_days'] . ' days' : null]))) ?></span>
                    <?= $c['detail'] ? '<br><span class="muted small">' . h($c['detail']) . '</span>' : '' ?></span>
                <form method="post" data-confirm="Remove <?= h($c['name']) ?>?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_contact"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="btn-link danger" type="submit">Remove</button>
                </form>
            </div>
            <details class="q-edit"><summary>Change</summary>
                <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="contact"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <?php contact_fields($c); ?><span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span></form>
            </details>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
    <details class="q-edit"<?= !$contacts['person'] && !$contacts['professional'] ? ' open' : '' ?>>
        <summary>Add a contact</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="contact">
            <?php contact_fields([]); ?><span class="inline-actions"><button class="btn btn-primary" type="submit">Add contact</button></span></form>
    </details>
</section>
<?php render_footer();
