<?php
/*
 * Symptom tracker: the user's own symptoms (racing thoughts, low appetite, ...),
 * rated 0 to 10 in each check-in, with a chart for each.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$today = local_today();
const SYMPTOM_IDEAS = ['Racing thoughts', 'Low appetite', 'Poor concentration', 'Irritability', 'Panic', 'Intrusive thoughts', 'Hearing voices',
    'Feeling detached', 'Urges to self-harm', 'Overeating', 'Restlessness', 'Tearfulness'];

if (is_post()) {
    require_post();
    switch (post('action')) {
        case 'add':
            $name = trim(mb_substr(post('name'), 0, 80));
            if ($name !== '') {
                $existing = q('SELECT id FROM mind_symptoms WHERE user_id = ? AND name = ?', [$uid, $name])->fetchColumn();
                $existing ? q('UPDATE mind_symptoms SET active = 1 WHERE id = ?', [$existing]) : q('INSERT INTO mind_symptoms (user_id, name) VALUES (?, ?)', [$uid, $name]);
                flash('success', "$name added. Rate it when you check in.");
            }
            break;
        case 'stop':
        case 'restart':
            q('UPDATE mind_symptoms SET active = ? WHERE id = ? AND user_id = ?', [post('action') === 'restart' ? 1 : 0, (int) post('id'), $uid]);
            break;
        case 'delete':
            q('DELETE FROM mind_symptoms WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            break;
    }
    redirect('mind_symptoms.php');
}

$symptoms = mind_symptoms($uid, false);
$from = $today->modify('-89 days');
$series = [];
foreach (q('SELECT l.symptom_id, l.severity, c.log_date FROM mind_symptom_logs l JOIN mind_checkins c ON c.id = l.checkin_id
            WHERE c.user_id = ? AND c.log_date >= ? ORDER BY c.log_date', [$uid, $from->format('Y-m-d')]) as $r) {
    $series[$r['symptom_id']][$r['log_date']][] = (int) $r['severity'];
}
$have = array_map('mb_strtolower', array_column($symptoms, 'name'));

render_header('Symptoms', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F4C8; Symptoms</h1>
        <p class="muted">Add the symptoms you want to keep an eye on. You rate each one, 0 to 10, when you check in.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
</div>

<section class="card">
    <h2>My symptoms</h2>
    <?php if (!$symptoms): ?><p class="muted">None yet.</p><?php endif; ?>
    <?php foreach ($symptoms as $s):
        $points = array_map(fn ($d, $v) => [(new DateTimeImmutable($d . ' 12:00', $today->getTimezone()))->getTimestamp(), array_sum($v) / count($v)], array_keys($series[$s['id']] ?? []), $series[$s['id']] ?? []); ?>
    <div class="symptom-block">
        <div class="logline"><span><strong><?= h($s['name']) ?></strong><?= $s['active'] ? '' : ' <span class="badge badge-skipped">Not tracking</span>' ?></span>
            <span class="inline-actions">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $s['active'] ? 'stop' : 'restart' ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-small" type="submit"><?= $s['active'] ? 'Stop tracking' : 'Track again' ?></button></form>
                <form method="post" data-confirm="Delete <?= h($s['name']) ?> and all its ratings?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button class="btn-link danger small" type="submit">Delete</button></form>
            </span></div>
        <?php if (count($points) > 1): ?>
        <?= svg_line_chart([['label' => $s['name'], 'unit' => '', 'points' => $points]], $from->getTimestamp(), $today->modify('+1 day')->getTimestamp(), $s['name']) ?>
        <?php elseif ($s['active']): ?><p class="muted small">Rate it in a few check-ins to see a chart.</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>Add a symptom <input name="name" maxlength="80" required></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
    <div class="preset-list">
        <?php foreach (SYMPTOM_IDEAS as $idea): if (in_array(mb_strtolower($idea), $have, true)) { continue; } ?>
        <form method="post" class="preset"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="name" value="<?= h($idea) ?>">
            <button class="btn btn-small" type="submit">+ <?= h($idea) ?></button></form>
        <?php endforeach; ?>
    </div>
</section>
<?php render_footer();
