<?php
/*
 * Therapy and care: mental health appointments (therapy, psychiatrist, reviews) and
 * what came out of them, actions agreed, a running list of questions for next time,
 * and the care team's contacts.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$today = local_today();

if (is_post()) {
    require_post();
    $kind = post('kind') === 'action' ? 'action' : 'question';
    switch (post('action')) {
        case 'add':
            $text = trim(mb_substr(post('text'), 0, 500));
            if ($text !== '') {
                q('INSERT INTO mind_notes (user_id, kind, text, due_date, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$uid, $kind, $text, parse_date(post('due_date'))?->format('Y-m-d')]);
            }
            break;
        case 'toggle':
            q('UPDATE mind_notes SET done = 1 - done WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            break;
        case 'delete':
            q('DELETE FROM mind_notes WHERE id = ? AND user_id = ?', [(int) post('id'), $uid]);
            break;
    }
    redirect('mind_care.php#' . ($kind === 'action' ? 'actions' : 'questions'));
}

$in = implode(',', array_fill(0, count(MIND_APPOINTMENT_KINDS), '?'));
$upcoming = q("SELECT * FROM appointments WHERE user_id = ? AND kind IN ($in) AND starts_at >= UTC_TIMESTAMP() ORDER BY starts_at LIMIT 5", [$uid, ...MIND_APPOINTMENT_KINDS])->fetchAll();
$past = q("SELECT * FROM appointments WHERE user_id = ? AND kind IN ($in) AND starts_at < UTC_TIMESTAMP() ORDER BY starts_at DESC LIMIT 6", [$uid, ...MIND_APPOINTMENT_KINDS])->fetchAll();
$questions = mind_notes($uid, 'question');
$actions = mind_notes($uid, 'action');
$team = mind_contacts($uid)['professional'];

/** A tickable list item for a question or action. */
function note_row(array $n, string $kind): void
{
    ?>
    <li class="question<?= $n['done'] ? ' done' : '' ?>">
        <form method="post" class="q-toggle"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="kind" value="<?= h($kind) ?>"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button type="submit" class="q-check" aria-pressed="<?= $n['done'] ? 'true' : 'false' ?>"><?= $n['done'] ? '&#10003;' : '' ?><span class="sr"><?= $n['done'] ? 'Done' : 'Not done' ?></span></button></form>
        <div class="q-body"><span class="q-text"><?= h($n['text']) ?></span>
            <span class="muted small"><?= implode(' · ', array_filter([
                $n['due_date'] ? 'by ' . h(date('j M', strtotime($n['due_date']))) : null,
                $n['appointment_id'] ? ($kind === 'question' ? 'added to ' : 'from ') . '<a href="' . h(url('appointment.php?id=' . $n['appointment_id'])) . '">'
                    . h(($n['appointment_title'] ?: appointment_type_label((string) $n['appointment_kind'])) . ', ' . local_time($n['starts_at'], 'j M')) . '</a>' : null,
            ])) ?></span></div>
        <form method="post" data-confirm="Remove this?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="kind" value="<?= h($kind) ?>"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button class="btn-link danger small" type="submit">Remove</button></form>
    </li>
    <?php
}

render_header('Therapy and care', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F91D; Therapy and care</h1>
        <p class="muted">Sessions and reviews, what was agreed, and what you want to bring up next time.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url('mind.php')) ?>">&larr; Mind</a>
</div>

<section class="card">
    <div class="card-head">
        <h2>Appointments</h2>
        <span class="inline-actions">
            <a class="btn btn-small" href="<?= h(url('appointment_edit.php?kind=mental_health')) ?>">+ Therapy session</a>
            <a class="btn btn-small" href="<?= h(url('appointment_edit.php?kind=psychiatrist')) ?>">+ Psychiatrist</a>
        </span>
    </div>
    <p class="muted small">Therapy, counselling and reviews are appointments of type "Mental health / therapy" or "Psychiatrist". Your questions
        and how things have been show as suggestions on the appointment, and you can note the outcome and actions agreed there.</p>
    <?php if (!$upcoming && !$past): ?><p class="muted">None yet.</p><?php endif; ?>
    <ul class="plain">
        <?php foreach ([...$upcoming, ...$past] as $a): $future = $a['starts_at'] >= utc_now(); ?>
        <li class="logline">
            <span><a href="<?= h(url('appointment.php?id=' . $a['id'])) ?>"><strong><?= h(local_time($a['starts_at'], 'D j M Y, H:i')) ?></strong></a>
                &middot; <?= h(appointment_title($a)) ?><?= $a['with_whom'] ? ' with ' . h($a['with_whom']) : '' ?>
                <?php if (!$future && $a['outcome_notes']): ?><br><span class="small"><?= h(mb_strimwidth($a['outcome_notes'], 0, 200, '…')) ?></span><?php endif; ?></span>
            <?= $future ? '<span class="badge badge-upcoming">Upcoming</span>' : (!$a['outcome_notes'] ? '<a class="btn btn-small" href="' . h(url('appointment.php?id=' . $a['id'] . '#outcome')) . '">Add notes</a>' : '') ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card" id="questions">
    <h2>Questions for next time</h2>
    <p class="muted small">Add things as they come up, so nothing gets forgotten in the room. They're suggested on your next therapy or review appointment.</p>
    <ul class="question-list"><?php foreach ($questions as $n) { note_row($n, 'question'); } ?></ul>
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="question">
        <label>Add <input name="text" maxlength="500" required placeholder="e.g. Could the dose be making me more tired?"></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
</section>

<section class="card" id="actions">
    <h2>Actions agreed</h2>
    <p class="muted small">Homework and things to try. Add them on the appointment, or here.</p>
    <ul class="question-list"><?php foreach ($actions as $n) { note_row($n, 'action'); } ?></ul>
    <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="action">
        <label>Action <input name="text" maxlength="500" required placeholder="e.g. Plan one enjoyable thing each day"></label>
        <label>By <span class="muted small">(optional)</span> <input type="date" name="due_date"></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>Care team</h2>
        <a class="btn btn-small" href="<?= h(url('mind_plan.php#contacts')) ?>">Manage contacts</a>
    </div>
    <?php if (!$team): ?><p class="muted">Add your GP, therapist, crisis team or keyworker as "Professional or service" contacts.</p><?php endif; ?>
    <ul class="plain contact-list">
        <?php foreach ($team as $c): ?>
        <li class="contact"><div><strong><?= h($c['name']) ?></strong><?= $c['detail'] ? '<br><span class="muted small">' . h($c['detail']) . '</span>' : '' ?></div>
            <div class="inline-actions"><?= contact_links($c) ?></div></li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card">
    <h2>Medication</h2>
    <p>Keep notes on side effects and how you've felt since starting or changing a dose, ready for reviews.</p>
    <a class="btn" href="<?= h(url('mind_meds.php')) ?>">Mental health medication</a>
</section>
<?php render_footer();
