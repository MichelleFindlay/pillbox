<?php
/*
 * Sharing: let someone else (a carer, partner, family member) see, or help manage, your
 * Pillbox, and open Pillboxes that have been shared with you. Always works on the
 * signed-in person's own account, even while looking at someone else's.
 */
require __DIR__ . '/../src/bootstrap.php';

require_login();
$me = real_user();
$myId = (int) $me['id'];
const SHARE_LEVELS = ['view' => 'View only', 'manage' => 'Can help (log doses, tick tasks, add readings)'];

if (is_post()) {
    require_post();
    $shareId = (int) post('share_id');
    switch (post('action')) {
        case 'add':
            $who = mb_strtolower(trim(post('who')));
            $person = $who === '' ? null : q('SELECT * FROM users WHERE (LOWER(username) = ? OR LOWER(email) = ?) AND is_active = 1 AND is_demo = 0', [$who, $who])->fetch();
            if ($me['is_demo']) {
                flash('error', 'Sharing is switched off in the demo.');
            } elseif (!$person) {
                flash('error', "There's no Pillbox account with that username or email. They need to have an account here first.");
            } elseif ((int) $person['id'] === $myId) {
                flash('error', "That's you.");
            } else {
                $level = isset(SHARE_LEVELS[post('level')]) ? post('level') : 'view';
                q('INSERT INTO data_shares (owner_id, grantee_id, level, include_mind) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE level = VALUES(level), include_mind = VALUES(include_mind)',
                    [$myId, $person['id'], $level, post('include_mind') === '1' ? 1 : 0]);
                if ($person['email'] && mail_enabled()) {
                    send_mail($person['email'], "{$me['display_name']} has shared their Pillbox with you",
                        "{$me['display_name']} has shared their Pillbox with you (" . mb_strtolower(SHARE_LEVELS[$level]) . ").\n\nOpen it from Sharing: " . absolute_url('sharing.php') . "\n",
                        mail_html('Shared with you', '<p>' . h($me['display_name']) . ' has shared their Pillbox with you (' . h(mb_strtolower(SHARE_LEVELS[$level])) . ').</p><p><a href="' . h(absolute_url('sharing.php')) . '">Open it</a></p>'));
                }
                flash('success', "{$person['display_name']} can now " . ($level === 'manage' ? 'see and help manage' : 'see') . ' your Pillbox.');
            }
            redirect('sharing.php');
        case 'update':
            q('UPDATE data_shares SET level = ?, include_mind = ? WHERE id = ? AND owner_id = ?',
                [isset(SHARE_LEVELS[post('level')]) ? post('level') : 'view', post('include_mind') === '1' ? 1 : 0, $shareId, $myId]);
            flash('success', 'Access updated.');
            redirect('sharing.php');
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

render_header('Sharing', 'sharing');
?>
<div class="page-head">
    <div>
        <h1>Sharing</h1>
        <p class="muted">Let a carer, partner or family member see your Pillbox, or help you keep it up to date.</p>
    </div>
</div>

<?php if ($withMe): ?>
<section class="card">
    <h2>Shared with you</h2>
    <ul class="plain">
        <?php foreach ($withMe as $s): ?>
        <li class="logline"><span><strong><?= h($s['display_name']) ?></strong> <span class="muted small"><?= h(SHARE_LEVELS[$s['level']] ?? $s['level']) ?><?= $s['include_mind'] ? ', including Mind' : '' ?></span></span>
            <span class="inline-actions">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-small btn-primary" type="submit">Open</button></form>
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
        <?php foreach ($mine as $s): ?>
        <li class="care-task">
            <div class="logline"><span><strong><?= h($s['display_name']) ?></strong> <span class="muted small"><?= h($s['username'] ?: $s['email']) ?></span>
                <br><span class="small"><?= h(SHARE_LEVELS[$s['level']] ?? $s['level']) ?><?= $s['include_mind'] ? ', including Mind' : '' ?></span>
                <span class="muted small">&middot; <?= $s['last_used_at'] ? 'last looked ' . h(local_time($s['last_used_at'], 'j M, H:i')) : 'not opened yet' ?></span></span>
                <form method="post" data-confirm="Stop sharing with <?= h($s['display_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Stop sharing</button></form></div>
            <details class="q-edit"><summary>Change</summary>
                <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="share_id" value="<?= (int) $s['id'] ?>">
                    <div class="repeat-choice"><?php foreach (SHARE_LEVELS as $k => $l): ?>
                        <label class="check"><input type="radio" name="level" value="<?= h($k) ?>"<?= checked($s['level'] === $k) ?>> <?= h($l) ?></label><?php endforeach; ?></div>
                    <label class="check"><input type="checkbox" name="include_mind" value="1"<?= checked((bool) $s['include_mind']) ?>> <span>Include Mind <span class="muted small">(check-ins, journal, safety plan)</span></span></label>
                    <span class="inline-actions"><button class="btn btn-small btn-primary" type="submit">Save</button></span></form>
            </details>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($me['is_demo']): ?>
    <p class="muted">Sharing is switched off in the demo.</p>
    <?php else: ?>
    <h3>Share with someone</h3>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>Their username or email <span class="muted small">(they need a Pillbox account here)</span><input name="who" required autocapitalize="none"></label>
        <div class="repeat-choice"><?php foreach (SHARE_LEVELS as $k => $l): ?>
            <label class="check"><input type="radio" name="level" value="<?= h($k) ?>"<?= checked($k === 'view') ?>> <?= h($l) ?></label><?php endforeach; ?></div>
        <label class="check"><input type="checkbox" name="include_mind" value="1"> <span>Include Mind <span class="muted small">(check-ins, journal, safety plan; off unless you choose it)</span></span></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Share</button></span>
    </form>
    <p class="muted small">They'll never see your account settings, sign-in details, sync links or data export, and can't share your Pillbox with anyone else.
        You can stop sharing at any time.</p>
    <?php endif; ?>
</section>
<?php render_footer();
