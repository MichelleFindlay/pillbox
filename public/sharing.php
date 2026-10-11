<?php
/*
 * Sharing: let someone else (a carer, partner, family member) see, or help with, parts of
 * your Pillbox, choosing what for each section, and open Pillboxes shared with you.
 * Always works on the signed-in person's own account, even while looking at someone else's.
 */
require __DIR__ . '/../src/bootstrap.php';

require_login();
$me = real_user();
$myId = (int) $me['id'];
$myToday = new DateTimeImmutable('today', home_tz($me));

if (is_post()) {
    require_post();
    $shareId = (int) post('share_id');
    $until = post('expires_on') === '' ? null : parse_date(post('expires_on'));
    if (post('expires_on') !== '' && (!$until || $until < $myToday)) {
        flash('error', 'The end date must be today or later, or leave it empty.');
        redirect('sharing.php');
    }
    switch (post('action')) {
        case 'add':
            $who = mb_strtolower(trim(post('who')));
            $person = $who === '' ? null : q('SELECT * FROM users WHERE (LOWER(username) = ? OR LOWER(email) = ?) AND is_active = 1 AND is_demo = 0', [$who, $who])->fetch();
            $perms = share_permissions_from_post();
            if ($me['is_demo']) {
                flash('error', 'Sharing is switched off in the demo.');
            } elseif (!$person) {
                flash('error', "There's no Pillbox account with that username or email. They need to have an account here first.");
            } elseif ((int) $person['id'] === $myId) {
                flash('error', "That's you.");
            } elseif (share_summary($perms) === 'Nothing yet') {
                flash('error', 'Choose at least one thing they can see.');
            } else {
                q('INSERT INTO data_shares (owner_id, grantee_id, permissions, expires_on) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE permissions = VALUES(permissions), expires_on = VALUES(expires_on)',
                    [$myId, $person['id'], json_encode($perms), $until?->format('Y-m-d')]);
                if ($person['email'] && mail_enabled()) {
                    $what = share_summary($perms) . ($until ? '. Until ' . $until->format('j F Y') : '');
                    send_mail($person['email'], "{$me['display_name']} has shared their Pillbox with you",
                        "{$me['display_name']} has shared their Pillbox with you.\n\n$what\n\nOpen it from Sharing: " . absolute_url('sharing.php') . "\n",
                        mail_html('Shared with you', '<p>' . h($me['display_name']) . ' has shared their Pillbox with you.</p><p>' . h($what) . '</p><p><a href="' . h(absolute_url('sharing.php')) . '">Open it</a></p>'));
                }
                flash('success', "Shared with {$person['display_name']}.");
            }
            redirect('sharing.php');
        case 'update':
            $perms = share_permissions_from_post();
            q('UPDATE data_shares SET permissions = ?, expires_on = ? WHERE id = ? AND owner_id = ?', [json_encode($perms), $until?->format('Y-m-d'), $shareId, $myId]);
            flash('success', 'Access updated.');
            redirect('sharing.php#share-' . $shareId);
        case 'revoke':
        case 'leave':
            q('DELETE FROM data_shares WHERE id = ? AND ' . (post('action') === 'revoke' ? 'owner_id' : 'grantee_id') . ' = ?', [$shareId, $myId]);
            flash('success', post('action') === 'revoke' ? 'Access removed.' : "You've removed it from your list.");
            redirect('sharing.php');
        case 'open':
            $share = q('SELECT * FROM data_shares WHERE id = ? AND grantee_id = ?', [$shareId, $myId])->fetch();
            if ($share) {
                $_SESSION['acting_for'] = (int) $share['owner_id'];
                unset($_SESSION['mind_unlocked_at']);
                q('UPDATE data_shares SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$share['id']]);
                redirect('index.php');
            }
            redirect('sharing.php');
        case 'stop':
            unset($_SESSION['acting_for'], $_SESSION['mind_unlocked_at']);
            redirect(safe_return(post('return'), 'sharing.php'));
    }
}

$mine = q('SELECT s.*, u.display_name, u.username, u.email FROM data_shares s JOIN users u ON u.id = s.grantee_id WHERE s.owner_id = ? ORDER BY u.display_name', [$myId])->fetchAll();
$withMe = q('SELECT s.*, u.display_name FROM data_shares s JOIN users u ON u.id = s.owner_id AND u.is_active = 1 WHERE s.grantee_id = ? ORDER BY u.display_name', [$myId])->fetchAll();
$ended = fn (array $s) => $s['expires_on'] && $s['expires_on'] < $myToday->format('Y-m-d');

/** The permission grid, presets and end date, for the add and change forms. */
function share_fields(array $perms, ?string $expires, string $prefix): void
{
    ?>
    <div class="preset-row">
        <span class="small muted">Quick choices:</span>
        <?php foreach (SHARE_PRESETS as $key => [$label, $values]): ?>
        <button class="btn btn-small" type="button" data-share-preset='<?= h(json_encode($values)) ?>'><?= h($label) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="table-wrap">
    <table class="compact share-grid">
        <thead><tr><th>Part of your Pillbox</th><th>Access</th></tr></thead>
        <tbody>
        <?php foreach (SHARE_SECTIONS as $key => [$label, $help, $levels]): ?>
            <tr>
                <th scope="row"><?= h($label) ?><span class="muted small"><?= h($help) ?></span></th>
                <td>
                    <div class="level-choice" role="radiogroup" aria-label="<?= h($label) ?>">
                        <?php foreach (['none' => 'No access'] + $levels as $level => $levelLabel): ?>
                        <label class="day"><input type="radio" name="perm[<?= h($key) ?>]" value="<?= h($level) ?>"<?= checked($perms[$key] === $level) ?>><span><?= h($levelLabel) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($key === 'care'): ?>
                    <details class="q-edit"<?= $perms['care_hidden'] ? ' open' : '' ?>><summary class="small">Keep some Care categories private</summary>
                        <div class="checks small">
                            <?php foreach (care_categories() as $area => $a): ?>
                            <label class="check"><input type="checkbox" name="care_hidden[]" value="<?= h($area) ?>"<?= checked(in_array($area, $perms['care_hidden'], true)) ?>> <?= $a['icon'] ?> <?= h($a['title']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php elseif ($key === 'mind'): ?>
                    <label class="check small"><input type="checkbox" name="journal" value="1"<?= checked($perms['journal']) ?>> <span>Include the journal and thought records</span></label>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <label class="narrow"><span>Until <span class="muted small">(optional: access ends after this date)</span></span>
        <input type="date" name="expires_on" min="<?= h(local_today()->format('Y-m-d')) ?>" value="<?= h($expires ?? '') ?>"></label>
    <?php
}

render_header('Sharing', 'sharing');
?>
<div class="page-head">
    <div>
        <h1>Sharing <?= help_link('sharing', '', 'Sharing') ?></h1>
        <p class="muted">Let a carer, partner or family member see parts of your Pillbox, or help you keep them up to date. You choose exactly what.</p>
    </div>
</div>

<?php if ($withMe): ?>
<section class="card">
    <h2>Shared with you</h2>
    <ul class="plain">
        <?php foreach ($withMe as $s): $p = share_permissions($s); ?>
        <li class="logline"><span><strong><?= h($s['display_name']) ?></strong>
            <?= $ended($s) ? '<span class="badge badge-skipped">Ended</span>' : ($s['expires_on'] ? '<span class="muted small">until ' . h(date('j M Y', strtotime($s['expires_on']))) . '</span>' : '') ?>
            <br><span class="muted small"><?= h(share_summary($p)) ?></span></span>
            <span class="inline-actions">
                <?php if (!$ended($s)): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-small btn-primary" type="submit">Open</button></form>
                <?php endif; ?>
                <form method="post" data-confirm="Remove <?= h($s['display_name']) ?>'s Pillbox from your list? They can share it again."><?= csrf_field() ?><input type="hidden" name="action" value="leave"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn-link danger small" type="submit">Remove</button></form>
            </span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <h2>People who can see your Pillbox</h2>
    <?php if (!$mine): ?><p class="muted">No one yet.</p><?php endif; ?>
    <ul class="plain">
        <?php foreach ($mine as $s): $p = share_permissions($s);
            $activity = q('SELECT * FROM share_activity WHERE share_id = ? ORDER BY happened_at DESC LIMIT 15', [$s['id']])->fetchAll(); ?>
        <li class="care-task" id="share-<?= (int) $s['id'] ?>">
            <div class="logline"><span><strong><?= h($s['display_name']) ?></strong> <span class="muted small"><?= h($s['username'] ?: $s['email']) ?></span>
                <?= $ended($s) ? '<span class="badge badge-skipped">Ended ' . h(date('j M', strtotime($s['expires_on']))) . '</span>' : ($s['expires_on'] ? '<span class="badge badge-upcoming">Until ' . h(date('j M Y', strtotime($s['expires_on']))) . '</span>' : '') ?>
                <br><span class="small"><?= h(share_summary($p)) ?><?= $p['care_hidden'] ? ' (not ' . h(implode(', ', array_map(fn ($a) => CARE_AREAS[$a]['title'], $p['care_hidden']))) . ')' : '' ?><?= $p['journal'] ? ' · journal included' : '' ?></span>
                <span class="muted small">&middot; <?= $s['last_used_at'] ? 'last looked ' . h(local_time($s['last_used_at'], 'j M, H:i')) : 'not opened yet' ?></span></span>
                <form method="post" data-confirm="Stop sharing with <?= h($s['display_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Stop sharing</button></form></div>
            <details class="q-edit"><summary>Change what they can do</summary>
                <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <?php share_fields($p, $s['expires_on'], 'share-' . $s['id']); ?>
                    <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span></form>
            </details>
            <?php if ($activity): ?>
            <details class="q-edit"><summary>Recent activity</summary>
                <ul class="plain small activity-list">
                    <?php foreach ($activity as $a): ?><li><span class="muted"><?= h(local_time($a['happened_at'], 'j M, H:i')) ?></span> <?= h(share_activity_label($a)) ?></li><?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($me['is_demo']): ?>
    <p class="muted">Sharing is switched off in the demo.</p>
    <?php else: ?>
    <details class="q-edit"<?= $mine ? '' : ' open' ?>>
        <summary>Share with someone</summary>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="add">
            <label>Their username or email <span class="muted small">(they need a Pillbox account here)</span><input name="who" required autocapitalize="none"></label>
            <?php share_fields(share_permissions(['permissions' => json_encode(SHARE_PRESETS['view_all'][1])]), null, 'new'); ?>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Share</button></span>
        </form>
    </details>
    <p class="muted small">They'll never see your account settings, sign-in details, sync links or data export, can't change your Mind PIN or permanently delete
        medicines, and can't share your Pillbox with anyone else. Everything they change is listed under their name here.</p>
    <?php endif; ?>
</section>
<?php render_footer();
