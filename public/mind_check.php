<?php
/*
 * PHQ-9 (depression) and GAD-7 (anxiety): take them, see the score and band, and
 * track scores over time. Any answer other than "Not at all" to PHQ-9 question 9
 * goes straight to Help now.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
require_mind($user);
$take = isset(QUESTIONNAIRES[get('take')]) ? get('take') : null;
$error = null;

if (is_post()) {
    require_post();
    $kind = post('kind');
    if (isset(QUESTIONNAIRES[$kind])) {
        $answers = [];
        foreach (array_keys(QUESTIONNAIRES[$kind]['items']) as $i) {
            $a = $_POST['q'][$i] ?? null;
            if (!is_string($a) || !in_array($a, ['0', '1', '2', '3'], true)) {
                $error = 'Answer every question. Choose "Not at all" if it doesn\'t apply.';
                $take = $kind;
                break;
            }
            $answers[] = (int) $a;
        }
        if (!$error) {
            $score = array_sum($answers);
            $difficulty = in_array(post('difficulty'), ['0', '1', '2', '3'], true) ? (int) post('difficulty') : null;
            q('INSERT INTO mind_questionnaires (user_id, kind, answers, score, difficulty, taken_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$uid, $kind, implode(',', $answers), $score, $difficulty]);
            $q = QUESTIONNAIRES[$kind];
            flash('success', $q['title'] . " score: $score out of {$q['max']} (" . mb_strtolower(questionnaire_band($kind, $score)) . ').');
            if ($kind === 'phq9' && $answers[8] > 0) {
                redirect('help.php?phq=1');
            }
            redirect('mind_check.php#' . $kind);
        }
    }
}

$history = [];
foreach (q('SELECT * FROM mind_questionnaires WHERE user_id = ? ORDER BY taken_at DESC', [$uid]) as $r) {
    $history[$r['kind']][] = $r;
}

render_header('Questionnaires', 'mind');
?>
<div class="page-head">
    <div>
        <h1>&#x1F4CB; Questionnaires</h1>
        <p class="muted">The PHQ-9 and GAD-7 are used across the NHS, including NHS Talking Therapies. Taking them every couple of weeks shows how things are changing.</p>
    </div>
    <a class="btn btn-small" href="<?= h(url($take ? 'mind_check.php' : 'mind.php')) ?>">&larr; <?= $take ? 'Questionnaires' : 'Mind' ?></a>
</div>

<?php if ($take): $q = QUESTIONNAIRES[$take]; ?>
<form method="post" class="card stack questionnaire">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= h($take) ?>">
    <h2><?= h($q['title']) ?> <span class="muted small">(<?= h(mb_strtolower($q['about'])) ?>)</span></h2>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <p><strong><?= h($q['intro']) ?></strong></p>
    <?php foreach ($q['items'] as $i => $item): ?>
    <fieldset class="q-item">
        <legend><?= $i + 1 ?>. <?= h($item) ?></legend>
        <div class="q-options">
            <?php foreach (QUESTIONNAIRE_OPTIONS as $v => $label): ?>
            <label class="q-option"><input type="radio" name="q[<?= $i ?>]" value="<?= $v ?>" required<?= checked(($_POST['q'][$i] ?? null) === (string) $v) ?>><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endforeach; ?>
    <fieldset class="q-item">
        <legend>If you were bothered by any of these, how difficult have they made it to do your work, take care of things at home, or get along with other people? <span class="muted small">(optional)</span></legend>
        <div class="q-options">
            <?php foreach (QUESTIONNAIRE_DIFFICULTY as $v => $label): ?>
            <label class="q-option"><input type="radio" name="difficulty" value="<?= $v ?>"><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <span class="inline-actions"><button class="btn btn-primary" type="submit">See my score</button></span>
</form>

<?php else: ?>
<?php foreach (QUESTIONNAIRES as $kind => $q): $rows = $history[$kind] ?? []; ?>
<section class="card" id="<?= h($kind) ?>">
    <div class="card-head">
        <h2><?= h($q['title']) ?> <span class="muted small">(<?= h(mb_strtolower($q['about'])) ?>, <?= count($q['items']) ?> questions)</span></h2>
        <a class="btn btn-small btn-primary" href="<?= h(url('mind_check.php?take=' . $kind)) ?>"><?= $rows ? 'Take again' : 'Take it' ?></a>
    </div>
    <?php if ($rows): $latest = $rows[0]; ?>
    <p class="score-line"><span class="score"><?= (int) $latest['score'] ?></span><span class="muted">out of <?= $q['max'] ?></span>
        <strong><?= h(questionnaire_band($kind, (int) $latest['score'])) ?></strong>
        <span class="muted small">on <?= h(local_time($latest['taken_at'], 'j M Y')) ?><?= isset($rows[1]) ? ', ' . (($d = (int) $latest['score'] - (int) $rows[1]['score']) === 0 ? 'same as last time' : ($d < 0 ? 'down ' . -$d : 'up ' . $d) . ' from last time') : '' ?></span></p>
    <?php if (count($rows) > 1):
        $pts = array_map(fn ($r) => [strtotime($r['taken_at']), (float) $r['score']], array_reverse($rows)); ?>
    <?= svg_line_chart([['label' => $q['title'], 'unit' => '', 'points' => $pts]], $pts[0][0] - 86400, end($pts)[0] + 86400, $q['title'] . ' scores') ?>
    <?php endif; ?>
    <details class="q-edit"><summary>All results</summary>
        <ul class="plain">
        <?php foreach ($rows as $r): ?>
            <li class="logline"><span><?= h(local_time($r['taken_at'], 'j M Y')) ?></span>
                <span><strong><?= (int) $r['score'] ?></strong> <span class="muted small"><?= h(questionnaire_band($kind, (int) $r['score'])) ?></span></span></li>
        <?php endforeach; ?>
        </ul>
    </details>
    <?php else: ?>
    <p class="muted">Not taken yet.</p>
    <?php endif; ?>
    <p class="muted small">Score bands: <?= h(implode(', ', array_map(fn ($max, $label) => $label . ' up to ' . $max, array_keys($q['bands']), $q['bands']))) ?>.</p>
</section>
<?php endforeach; ?>
<p class="muted small">These questionnaires don't give a diagnosis. If your scores are high, or you're worried, talk to your GP or care team: the results
    are useful to show them. PHQ-9 and GAD-7 were developed by Drs Spitzer, Williams, Kroenke and colleagues, and are free to use.</p>
<?php endif; ?>
<?php render_footer();
