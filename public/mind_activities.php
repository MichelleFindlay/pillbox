<?php
/*
 * Activity scheduling (behavioural activation): plan small enjoyable or meaningful
 * activities, then say how they went (enjoyment and achievement, 0 to 10).
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$today = local_today();

if (is_post()) {
    require_post();
    $own = fn () => q('SELECT * FROM mind_activities WHERE id = ? AND user_id = ?', [(int) post('id'), $uid])->fetch();
    switch (post('action')) {
        case 'plan':
            $activity = trim(mb_substr(post('activity'), 0, 200));
            $day = parse_date(post('planned_for')) ?? $today;
            $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d/', post('planned_time')) ? substr(post('planned_time'), 0, 5) : null;
            if ($activity !== '') {
                q('INSERT INTO mind_activities (user_id, planned_for, planned_time, activity) VALUES (?, ?, ?, ?)', [$uid, $day->format('Y-m-d'), $time, $activity]);
                flash('success', 'Planned: ' . $activity . ($day == $today ? ' today.' : ' on ' . $day->format('l j F') . '.'));
            }
            break;
        case 'rate':
            if ($own()) {
                $rating = fn (string $f) => post($f) === '' ? null : max(0, min(10, (int) post($f)));
                $status = post('status') === 'skipped' ? 'skipped' : 'done';
                q('UPDATE mind_activities SET status = ?, enjoyment = ?, achievement = ?, note = ? WHERE id = ?',
                    [$status, $status === 'done' ? $rating('enjoyment') : null, $status === 'done' ? $rating('achievement') : null,
                        trim(mb_substr(post('note'), 0, 500)) ?: null, (int) post('id')]);
                flash('success', $status === 'done' ? 'Well done for doing it.' : "That's OK. Maybe try something smaller next time.");
            }
            break;
        case 'delete':
            q('DELETE FROM mind_activities WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            break;
    }
    redirect('mind_activities.php');
}

$planned = q("SELECT * FROM mind_activities WHERE user_id = ? AND status = 'planned' ORDER BY planned_for, planned_time IS NULL, planned_time", [$uid])->fetchAll();
$toRate = array_filter($planned, fn ($a) => $a['planned_for'] <= $today->format('Y-m-d'));
$ahead = array_filter($planned, fn ($a) => $a['planned_for'] > $today->format('Y-m-d'));
$history = q("SELECT * FROM mind_activities WHERE user_id = ? AND status <> 'planned' ORDER BY planned_for DESC, id DESC LIMIT 30", [$uid])->fetchAll();
$done = array_filter($history, fn ($a) => $a['status'] === 'done');
$avg = fn (string $f) => ($v = array_filter(array_column($done, $f), fn ($x) => $x !== null)) ? array_sum($v) / count($v) : null;

render_header('Activities', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F5D3;&#xFE0F; Activities</h1>
        <p class="muted">Plan small things that are enjoyable or feel worthwhile, then notice how they went. Doing comes first; motivation often follows.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
</div>

<?php if ($toRate): ?>
<section class="card">
    <h2>How did it go?</h2>
    <ul class="plain">
    <?php foreach ($toRate as $a): ?>
        <li class="care-task">
            <div class="logline"><span><strong><?= h($a['activity']) ?></strong>
                <span class="muted small"><?= h($a['planned_for'] === $today->format('Y-m-d') ? 'Today' : date('D j M', strtotime($a['planned_for']))) ?><?= $a['planned_time'] ? ', ' . h(substr($a['planned_time'], 0, 5)) : '' ?></span></span>
                <form method="post" data-confirm="Remove this plan?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn-link danger small" type="submit">Remove</button></form></div>
            <form method="post" class="rate-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="rate"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <label>Enjoyment <span class="muted small">(0 to 10)</span><select name="enjoyment"><option value="">&ndash;</option><?php for ($i = 0; $i <= 10; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></label>
                <label>Sense of achievement <span class="muted small">(0 to 10)</span><select name="achievement"><option value="">&ndash;</option><?php for ($i = 0; $i <= 10; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></label>
                <label>Note <span class="muted small">(optional)</span><input name="note" maxlength="500"></label>
                <span class="inline-actions">
                    <button class="btn btn-primary" type="submit" name="status" value="done">I did it</button>
                    <button class="btn" type="submit" name="status" value="skipped" formnovalidate>Didn't happen</button>
                </span>
            </form>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <h2>Plan something</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="plan">
        <div class="grid-2">
            <label>Activity <input name="activity" maxlength="200" required list="activity-ideas" placeholder="Something small is fine"></label>
            <label>Day <input type="date" name="planned_for" min="<?= h($today->format('Y-m-d')) ?>" value="<?= h($today->format('Y-m-d')) ?>"></label>
            <label>Time <span class="muted small">(optional)</span><input type="time" name="planned_time"></label>
        </div>
        <datalist id="activity-ideas"><?php foreach (ACTIVITY_IDEAS as $i): ?><option value="<?= h($i) ?>"><?php endforeach; ?></datalist>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Plan it</button></span>
    </form>
    <?php if ($ahead): ?>
    <h3>Coming up</h3>
    <ul class="plain"><?php foreach ($ahead as $a): ?>
        <li class="logline"><span><strong><?= h(date('D j M', strtotime($a['planned_for']))) ?></strong><?= $a['planned_time'] ? ' ' . h(substr($a['planned_time'], 0, 5)) : '' ?> &middot; <?= h($a['activity']) ?></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn-link danger small" type="submit">Remove</button></form></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<?php if ($history): ?>
<section class="card">
    <h2>Done recently</h2>
    <?php if ($done): ?>
    <p class="small">Average enjoyment <strong><?= $avg('enjoyment') === null ? '–' : number_format($avg('enjoyment'), 1) ?></strong>,
        achievement <strong><?= $avg('achievement') === null ? '–' : number_format($avg('achievement'), 1) ?></strong> out of 10, over <?= count($done) ?> activities.</p>
    <?php endif; ?>
    <ul class="plain"><?php foreach ($history as $a): ?>
        <li class="logline"><span><strong><?= h(date('D j M', strtotime($a['planned_for']))) ?></strong> &middot; <?= h($a['activity']) ?>
            <span class="muted small"><?= $a['status'] === 'skipped' ? "didn't happen" : implode(' · ', array_filter([
                $a['enjoyment'] !== null ? 'enjoyment ' . (int) $a['enjoyment'] . '/10' : null, $a['achievement'] !== null ? 'achievement ' . (int) $a['achievement'] . '/10' : null])) ?></span>
            <?= $a['note'] ? '<br><span class="small">' . h($a['note']) . '</span>' : '' ?></span></li>
    <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php render_footer();
