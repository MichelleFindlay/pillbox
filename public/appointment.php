<?php
/*
 * One appointment: notes, questions to ask (ticked off and answered on the day),
 * suggested topics, and what was decided afterwards. Prints as a one-page sheet.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$a = find_appointment((int) get('id'), $uid) ?? not_found();
$self = 'appointment.php?id=' . $a['id'];

if (is_post()) {
    require_post();
    $questionId = (int) post('question_id');
    $owned = fn () => q('SELECT 1 FROM appointment_questions WHERE id = ? AND appointment_id = ?', [$questionId, $a['id']])->fetch();
    switch (post('action')) {
        case 'add_question':
            $text = mb_substr(post('question'), 0, 500);
            if ($text !== '') {
                q('INSERT INTO appointment_questions (appointment_id, question) VALUES (?, ?)', [$a['id'], $text]);
            }
            redirect($self . '#questions');
        case 'toggle_question':
            if ($owned()) {
                q('UPDATE appointment_questions SET done = 1 - done WHERE id = ?', [$questionId]);
            }
            redirect($self . '#questions');
        case 'save_answer':
            if ($owned()) {
                q('UPDATE appointment_questions SET answer = ?, done = IF(CHAR_LENGTH(?) > 0, 1, done) WHERE id = ?',
                    [post('answer') ?: null, post('answer'), $questionId]);
                flash('success', 'Answer saved.');
            }
            redirect($self . '#q-' . $questionId);
        case 'delete_question':
            if ($owned()) {
                q('DELETE FROM appointment_questions WHERE id = ?', [$questionId]);
            }
            redirect($self . '#questions');
        case 'save_outcome':
            q('UPDATE appointments SET outcome_notes = ? WHERE id = ?', [mb_substr(post('outcome_notes'), 0, 10000) ?: null, $a['id']]);
            flash('success', 'Notes from the appointment saved.');
            redirect($self . '#outcome');
    }
    redirect($self);
}

$questions = appointment_questions((int) $a['id']);
$asked = array_column($questions, 'question');
$isPast = $a['starts_at'] < gmdate('Y-m-d H:i:s', time() - 3600);
$suggestions = $isPast ? [] : array_values(array_filter(appointment_suggestions($user, $a), fn ($s) => !in_array($s, $asked, true)));
$start = (new DateTimeImmutable($a['starts_at'], new DateTimeZone('UTC')))->setTimezone(user_tz());
$end = $start->modify('+' . (int) $a['duration_minutes'] . ' minutes');

render_header(appointment_title($a), 'appointments');
?>
<div class="page-head no-print">
    <a class="btn btn-small" href="<?= h(url('appointments.php')) ?>">&larr; All appointments</a>
    <span class="inline-actions">
        <button class="btn btn-small" type="button" data-print>Print / save as PDF</button>
        <a class="btn btn-small" href="<?= h(url('appointment_edit.php?id=' . $a['id'])) ?>">Edit details</a>
    </span>
</div>

<article class="appt-sheet">
<section class="card">
    <h1><?= h(appointment_title($a)) ?></h1>
    <p class="appt-when"><strong><?= h($start->format('l j F Y')) ?>, <?= h($start->format('H:i')) ?>–<?= h($end->format('H:i')) ?></strong></p>
    <p class="muted"><?= h(implode(' · ', array_filter([
        appointment_type_label($a['kind']),
        $a['with_whom'] ? 'with ' . $a['with_whom'] : '',
        (string) $a['location'],
    ]))) ?></p>
    <p class="print-only small">Patient: <?= h($user['display_name']) ?></p>
    <?php if ($a['notes']): ?>
    <h2>Before the appointment</h2>
    <p class="appt-notes"><?= nl2br(h($a['notes'])) ?></p>
    <?php endif; ?>
</section>

<section class="card" id="questions">
    <h2>Questions to ask <?php if ($questions): ?><span class="muted small">(<?= count(array_filter($questions, fn ($q) => $q['done'])) ?> of <?= count($questions) ?> asked)</span><?php endif; ?></h2>
    <?php if (!$questions): ?><p class="muted no-print">Nothing yet. Add anything you want to ask or mention, so you don't forget on the day.</p><?php endif; ?>
    <ul class="question-list">
        <?php foreach ($questions as $qn): ?>
        <li id="q-<?= (int) $qn['id'] ?>" class="question<?= $qn['done'] ? ' done' : '' ?>">
            <form method="post" class="q-toggle">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_question"><input type="hidden" name="question_id" value="<?= (int) $qn['id'] ?>">
                <button type="submit" class="q-check" aria-pressed="<?= $qn['done'] ? 'true' : 'false' ?>" title="<?= $qn['done'] ? 'Mark as not asked' : 'Mark as asked' ?>"><?= $qn['done'] ? '&#10003;' : '' ?><span class="sr"><?= $qn['done'] ? 'Asked' : 'Not asked yet' ?></span></button>
            </form>
            <div class="q-body">
                <span class="q-text"><?= h($qn['question']) ?></span>
                <?php if ($qn['answer']): ?><p class="q-answer"><?= nl2br(h($qn['answer'])) ?></p><?php endif; ?>
                <details class="no-print q-edit"<?= $qn['done'] && !$qn['answer'] ? ' open' : '' ?>>
                    <summary class="small">What did they say?</summary>
                    <form method="post" class="stack">
                        <?= csrf_field() ?><input type="hidden" name="action" value="save_answer"><input type="hidden" name="question_id" value="<?= (int) $qn['id'] ?>">
                        <textarea name="answer" rows="2" maxlength="5000"><?= h($qn['answer']) ?></textarea>
                        <span class="inline-actions"><button class="btn btn-small" type="submit">Save answer</button></span>
                    </form>
                </details>
                <span class="print-only answer-lines"></span>
            </div>
            <form method="post" class="no-print" data-confirm="Remove this question?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?= (int) $qn['id'] ?>">
                <button class="btn-link danger small" type="submit">Remove</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" class="inline-form no-print">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_question">
        <label>Add a question or something to mention <input name="question" maxlength="500" required placeholder="e.g. Can I take ibuprofen with my new tablets?"></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>

    <?php if ($suggestions): ?>
    <div class="suggestions no-print">
        <h3>You might want to mention</h3>
        <p class="muted small">From what you've recorded in Pillbox recently. Add any you'd like to bring up.</p>
        <ul class="plain">
            <?php foreach ($suggestions as $sg): ?>
            <li class="logline">
                <span><?= h($sg) ?></span>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_question"><input type="hidden" name="question" value="<?= h($sg) ?>">
                    <button class="btn btn-small" type="submit">+ Add</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</section>

<section class="card" id="outcome">
    <h2>After the appointment</h2>
    <form method="post" class="stack no-print">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_outcome">
        <label><span>What was decided? <span class="muted small">Diagnosis, changes to medicines, tests, follow-ups...</span></span>
            <textarea name="outcome_notes" rows="4" maxlength="10000"><?= h($a['outcome_notes']) ?></textarea></label>
        <span class="inline-actions">
            <button class="btn btn-primary" type="submit">Save notes</button>
            <a class="btn" href="<?= h(url('medications.php')) ?>">Update my medicines</a>
            <a class="btn" href="<?= h(url('appointment_edit.php')) ?>">Book a follow-up</a>
        </span>
    </form>
    <?php if ($a['outcome_notes']): ?><p class="print-only"><?= nl2br(h($a['outcome_notes'])) ?></p><?php else: ?><span class="print-only answer-lines tall"></span><?php endif; ?>
</section>
</article>
<?php render_footer();
