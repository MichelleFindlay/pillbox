<?php
/*
 * How a medicine is working: per day, when it was taken, kicked in and wore off (and any crash),
 * a rating and side effects. Shows a timeline of recent days, a titration table (each dose
 * compared), and, with cycle tracking, whether it works differently before a period.
 * Useful for ADHD stimulants, but works for any medicine.
 */
require __DIR__ . '/../src/bootstrap.php';

const EFFECT_SIDE_EFFECTS = ['appetite' => 'Less appetite', 'sleep' => 'Poor sleep', 'mood' => 'Low or irritable mood', 'heart' => 'Racing heart',
    'tics' => 'Tics', 'headache' => 'Headache', 'stomach' => 'Stomach ache', 'other' => 'Other'];
const EFFECT_RATINGS = [1 => 'Not at all', 2 => 'A little', 3 => 'Somewhat', 4 => 'Well', 5 => 'Very well'];

$user = require_login();
$uid = (int) $user['id'];
$med = find_medication((int) get('id'), $uid) ?? not_found();
$today = local_today();
$todayYmd = $today->format('Y-m-d');
$schedules = schedules_by_medication([(int) $med['id']], $todayYmd)[$med['id']] ?? [];
$doseText = trim(($med['strength'] ? $med['strength'] . ' · ' : '') . medication_regimen($med, $schedules));

if (is_post()) {
    require_post();
    if (post('action') === 'delete') {
        q('DELETE FROM dose_effects WHERE id = ? AND user_id = ?', [(int) post('effect_id'), $uid]);
    } else {
        $time = fn (string $f) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', post($f)) ? post($f) . ':00' : null;
        $day = min(parse_date(post('date')) ?: $today, $today);
        q('INSERT INTO dose_effects (user_id, medication_id, log_date, dose, taken_at, onset_at, wore_off_at, crash, rating, side_effects, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $uid, $med['id'], $day->format('Y-m-d'), mb_substr($doseText, 0, 150), $time('taken_at'), $time('onset_at'), $time('wore_off_at'), post('crash') === '1' ? 1 : 0,
            isset(EFFECT_RATINGS[(int) post('rating')]) ? (int) post('rating') : null,
            implode(',', array_intersect(array_keys(EFFECT_SIDE_EFFECTS), (array) ($_POST['side'] ?? []))) ?: null, trim(mb_substr(post('notes'), 0, 500)) ?: null,
        ]);
        flash('success', 'Saved.');
    }
    redirect('med_effects.php?id=' . $med['id']);
}

$effects = q('SELECT * FROM dose_effects WHERE medication_id = ? ORDER BY log_date DESC, id DESC', [$med['id']])->fetchAll();
// Today's logged dose time, as the default for "taken at".
$takenToday = q("SELECT MIN(taken_at) FROM dose_logs WHERE medication_id = ? AND status = 'taken' AND taken_at >= ? AND taken_at < ?",
    [$med['id'], $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $today->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')])->fetchColumn();
$hours = fn (?string $a, ?string $b) => $a && $b && $b > $a ? (strtotime("2000-01-01 $b") - strtotime("2000-01-01 $a")) / 3600 : null;

// Titration: each dose compared.
$byDose = [];
foreach ($effects as $e) {
    $byDose[$e['dose'] ?: 'Not recorded'][] = $e;
}
// Cycle link: ratings before a period vs other days.
$cycle = ['luteal' => [], 'other' => []];
if (cycle_anchors($uid)) {
    foreach ($effects as $e) {
        if ($e['rating']) {
            $cycle[is_luteal_day($uid, $e['log_date']) ? 'luteal' : 'other'][] = (int) $e['rating'];
        }
    }
}

render_header($med['name'], 'meds');
?>
<div class="page-head">
    <div>
        <p class="muted small"><a href="<?= h(url('medications.php#med-' . $med['id'])) ?>">Meds</a> &rsaquo;</p>
        <h1><?= h($med['name']) ?>: how it's working</h1>
        <p class="muted"><?= h($doseText) ?></p>
    </div>
</div>

<section class="card">
    <h2>Log a day</h2>
    <form method="post" class="stack"><?= csrf_field() ?>
        <div class="grid-2">
            <label>Date <input type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
            <label>Taken at <input type="time" name="taken_at" value="<?= h($takenToday ? local_time($takenToday, 'H:i') : '') ?>"></label>
            <label>Started working at <input type="time" name="onset_at"></label>
            <label>Wore off at <input type="time" name="wore_off_at"></label>
        </div>
        <label class="check"><input type="checkbox" name="crash" value="1"> A crash or rebound when it wore off</label>
        <fieldset><legend>How well did it work?</legend>
            <div class="repeat-choice"><?php foreach (EFFECT_RATINGS as $k => $l): ?><label class="check"><input type="radio" name="rating" value="<?= $k ?>"> <?= h($l) ?></label><?php endforeach; ?></div>
        </fieldset>
        <fieldset><legend>Side effects</legend>
            <div class="checks"><?php foreach (EFFECT_SIDE_EFFECTS as $k => $l): ?><label class="check"><input type="checkbox" name="side[]" value="<?= $k ?>"> <?= h($l) ?></label><?php endforeach; ?></div>
        </fieldset>
        <label>Notes <input name="notes" maxlength="500"></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
</section>

<?php if ($effects): ?>
<section class="card">
    <h2>Recent days</h2>
    <?php $recent = array_slice($effects, 0, 14); $w = 640; $pad = 70; $x = fn (?string $t) => $t ? $pad + (strtotime("2000-01-01 $t") - strtotime('2000-01-01 05:00')) / (19 * 3600) * ($w - $pad - 8) : null; ?>
    <div class="chart"><svg viewBox="0 0 <?= $w ?> <?= 24 + count($recent) * 20 ?>" class="effect-timeline" role="img" aria-label="When it was taken, worked and wore off">
        <?php foreach ([6, 9, 12, 15, 18, 21, 24] as $hr): $hx = $pad + ($hr - 5) / 19 * ($w - $pad - 8); ?>
        <line class="grid" x1="<?= round($hx, 1) ?>" x2="<?= round($hx, 1) ?>" y1="14" y2="<?= 24 + count($recent) * 20 ?>"/><text class="axis" x="<?= round($hx, 1) ?>" y="10" text-anchor="middle"><?= sprintf('%02d:00', $hr % 24) ?></text>
        <?php endforeach; ?>
        <?php foreach ($recent as $i => $e): $y = 22 + $i * 20; ?>
        <text class="axis" x="0" y="<?= $y + 8 ?>"><?= h(date('D j M', strtotime($e['log_date']))) ?></text>
        <?php if ($e['taken_at'] && $e['onset_at'] && $x($e['onset_at']) > $x($e['taken_at'])): ?><rect class="eff-wait" x="<?= round($x($e['taken_at']), 1) ?>" y="<?= $y + 3 ?>" width="<?= round($x($e['onset_at']) - $x($e['taken_at']), 1) ?>" height="6" rx="3"><title>Taken <?= h(substr($e['taken_at'], 0, 5)) ?></title></rect><?php endif; ?>
        <?php if ($e['onset_at'] && $e['wore_off_at'] && $x($e['wore_off_at']) > $x($e['onset_at'])): ?><rect class="eff-on" x="<?= round($x($e['onset_at']), 1) ?>" y="<?= $y ?>" width="<?= round($x($e['wore_off_at']) - $x($e['onset_at']), 1) ?>" height="12" rx="4"><title>Working <?= h(substr($e['onset_at'], 0, 5)) ?> to <?= h(substr($e['wore_off_at'], 0, 5)) ?></title></rect><?php endif; ?>
        <?php if ($e['taken_at']): ?><circle class="eff-taken" cx="<?= round($x($e['taken_at']), 1) ?>" cy="<?= $y + 6 ?>" r="4"/><?php endif; ?>
        <?php if ($e['crash'] && $e['wore_off_at']): ?><text class="eff-crash" x="<?= round($x($e['wore_off_at']) + 4, 1) ?>" y="<?= $y + 10 ?>">crash</text><?php endif; ?>
        <?php endforeach; ?>
    </svg></div>
    <p class="muted small chart-key">Dot: taken · thin bar: waiting for it to work · thick bar: working.</p>
</section>

<section class="card">
    <h2>Each dose compared</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Dose</th><th class="num">Days</th><th class="num">Rating (of 5)</th><th class="num">Kicks in after</th><th class="num">Lasts</th><th>Side effects</th></tr></thead>
        <tbody><?php foreach ($byDose as $dose => $rows):
            $ratings = array_filter(array_column($rows, 'rating'));
            $onsets = array_filter(array_map(fn ($e) => $hours($e['taken_at'], $e['onset_at']), $rows));
            $durations = array_filter(array_map(fn ($e) => $hours($e['onset_at'], $e['wore_off_at']), $rows));
            $sides = array_count_values(array_merge(...array_map(fn ($e) => array_filter(explode(',', (string) $e['side_effects'])), $rows)));
            arsort($sides); ?>
            <tr><td><?= h($dose) ?><br><span class="muted small"><?= h(date('j M', strtotime(end($rows)['log_date']))) ?> to <?= h(date('j M', strtotime($rows[0]['log_date']))) ?></span></td>
                <td class="num"><?= count($rows) ?></td><td class="num"><?= $ratings ? h(number_format(array_sum($ratings) / count($ratings), 1)) : '–' ?></td>
                <td class="num"><?= $onsets ? h(number_format(array_sum($onsets) / count($onsets) * 60)) . ' min' : '–' ?></td>
                <td class="num"><?= $durations ? h(number_format(array_sum($durations) / count($durations), 1)) . ' h' : '–' ?></td>
                <td class="small"><?= h(implode(', ', array_map(fn ($k, $n) => EFFECT_SIDE_EFFECTS[$k] . " ($n)", array_keys($sides), $sides)) ?: '–') ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php if (count($cycle['luteal']) >= 3 && count($cycle['other']) >= 3): ?>
    <p class="small">In the 2 weeks before a period it rated <?= h(number_format(array_sum($cycle['luteal']) / count($cycle['luteal']), 1)) ?> on average, and
        <?= h(number_format(array_sum($cycle['other']) / count($cycle['other']), 1)) ?> at other times. Hormones can change how well some medicines (such as ADHD medication) work: worth mentioning at your review.</p>
    <?php endif; ?>
    <?php if ($med['controlled']): ?>
    <p class="small">NICE recommends regular checks of blood pressure, pulse and weight on stimulants: <a href="<?= h(url('readings.php')) ?>">log them in Readings</a>,
        and set review reminders in <a href="<?= h(url('care.php?area=neuro')) ?>">ADHD and autism</a>.</p>
    <?php endif; ?>
</section>

<section class="card">
    <details class="q-edit"><summary>All entries (<?= count($effects) ?>)</summary>
        <ul class="plain"><?php foreach ($effects as $e): ?>
            <li class="logline"><span><strong><?= h(date('D j M', strtotime($e['log_date']))) ?></strong>
                <?= $e['taken_at'] ? ' · taken ' . h(substr($e['taken_at'], 0, 5)) : '' ?><?= $e['onset_at'] ? ', working ' . h(substr($e['onset_at'], 0, 5)) : '' ?><?= $e['wore_off_at'] ? ' to ' . h(substr($e['wore_off_at'], 0, 5)) : '' ?>
                <?= $e['crash'] ? ' · crash' : '' ?><?= $e['rating'] ? ' · ' . h(mb_strtolower(EFFECT_RATINGS[$e['rating']])) : '' ?>
                <span class="muted small"><?= $e['side_effects'] ? h(implode(', ', array_map(fn ($k) => mb_strtolower(EFFECT_SIDE_EFFECTS[$k] ?? $k), explode(',', $e['side_effects'])))) : '' ?><?= $e['notes'] ? ' · ' . h($e['notes']) : '' ?></span></span>
                <form method="post" data-confirm="Delete this entry?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="effect_id" value="<?= (int) $e['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form></li>
        <?php endforeach; ?></ul>
    </details>
</section>
<?php endif; ?>
<?php render_footer();
