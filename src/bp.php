<?php
declare(strict_types=1);

/*
 * Blood pressure (a Care category, care.php?area=bp), for high blood pressure. Following NICE:
 * - Guided home monitoring: seated, 2 readings a minute apart, morning and evening, for 4 to 7
 *   days; day 1 is left out and the rest averaged, against a home target (under 135/85, or
 *   145/85 from 80), which a clinician can change. Pulse and irregular heartbeat flags too.
 * - Readings chart with BP medicine changes marked; morning and evening averages; monitor details.
 * - Medicines recognised by type, side effect buttons (dry cough, ankle swelling, dizziness), and
 *   sick-day guidance only when the person's clinician has given it.
 * - Safety prompts on very high or low readings (readings.php and here), review and blood test
 *   reminders, a monitor CSV import, and a clinician summary (bp_summary.php).
 */

const BP_CLASSES = [
    'acei' => ['ACE inhibitor', '/\b\w+pril\b/i'],
    'arb'  => ['ARB', '/\b\w+sartan\b/i'],
    'ccb'  => ['Calcium channel blocker', '/\b(amlodipine|felodipine|nifedipine|lercanidipine|lacidipine|diltiazem|verapamil)\b/i'],
    'diuretic' => ['Diuretic', '/\b(indapamide|bendroflumethiazide|chlortalidone|hydrochlorothiazide|furosemide|bumetanide|spironolactone|eplerenone)\b/i'],
    'beta' => ['Beta blocker', '/\b\w+olol\b/i'],
    'other' => ['Other', '/\b(doxazosin|moxonidine|hydralazine|clonidine|methyldopa|labetalol)\b/i'],
];
// Common side effects by type: [label, medicine types it's linked with].
const BP_SIDE_EFFECTS = ['cough' => ['Dry cough', ['acei']], 'ankles' => ['Ankle swelling', ['ccb']], 'dizzy' => ['Dizziness or light-headed', []],
    'tired' => ['Tiredness', ['beta']], 'cold_hands' => ['Cold hands or feet', ['beta']], 'wee' => ['Needing to wee more', ['diuretic']], 'flushing' => ['Flushing or headache', ['ccb']]];
const BP_SESSION_DAYS = 7;

/** The BP medicine type of a medicine, or null. */
function bp_class(string $name): ?string
{
    foreach (BP_CLASSES as $key => [, $pattern]) {
        if (preg_match($pattern, $name)) {
            return $key;
        }
    }
    return null;
}

function bp_profile(int $userId): array
{
    return q('SELECT * FROM bp_profiles WHERE user_id = ?', [$userId])->fetch()
        ?: ['user_id' => $userId, 'target_sys' => null, 'target_dia' => null, 'monitor' => null, 'cuff' => null, 'validated' => 0, 'sick_day' => 0, 'session_start' => null];
}

/** The home target: the clinician's, or NICE's by age. @return array{0: int, 1: int, 2: string} */
function bp_target(array $user, array $profile): array
{
    if ($profile['target_sys'] && $profile['target_dia']) {
        return [(int) $profile['target_sys'], (int) $profile['target_dia'], 'set by your clinician'];
    }
    $age = user_age($user);
    return $age !== null && $age >= 80 ? [145, 85, 'the usual home target from 80'] : [135, 85, 'the usual home target under 80'];
}

/** [level, advice] for one reading, or null if it's unremarkable. */
function bp_advice(int $sys, int $dia): ?array
{
    if ($sys >= 180 || $dia >= 120) {
        return ['bad', "That's very high. Sit quietly for 5 minutes and measure again. If it's still 180/120 or more and you have chest pain, a severe headache, vision changes, confusion, weakness or breathlessness, call 999. Otherwise contact your GP today, or NHS 111."];
    }
    if ($sys < 90) {
        return ['warn', 'That reading is low. If you feel dizzy, faint or unwell, sit or lie down, and contact your GP (or NHS 111) if it keeps happening.'];
    }
    return null;
}

/** Blood pressure readings since a time, oldest first. */
function bp_readings(int $userId, string $fromUtc): array
{
    return q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'blood_pressure' AND recorded_at >= ? ORDER BY recorded_at", [$userId, $fromUtc])->fetchAll();
}

/**
 * The current or latest home monitoring session: the days logged, and the average of every
 * reading after day 1 (NICE). @return array{start: string, days: array, average: ?array, done: bool}|null
 */
function bp_session(array $user, array $profile, DateTimeImmutable $today): ?array
{
    if (!$profile['session_start']) {
        return null;
    }
    $start = new DateTimeImmutable($profile['session_start'], $today->getTimezone());
    $end = $start->modify('+' . (BP_SESSION_DAYS - 1) . ' days');
    $rows = q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'blood_pressure' AND context = 'Home monitoring' AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at",
        [$user['id'], $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $end->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')])->fetchAll();
    $days = [];
    foreach ($rows as $r) {
        $local = local_time($r['recorded_at'], 'Y-m-d');
        $days[$local][(int) local_time($r['recorded_at'], 'G') < 13 ? 'morning' : 'evening'][] = $r;
    }
    $counted = array_filter($rows, fn ($r) => local_time($r['recorded_at'], 'Y-m-d') !== $start->format('Y-m-d'));
    $average = count($counted) >= 2 ? ['sys' => array_sum(array_column($counted, 'value1')) / count($counted), 'dia' => array_sum(array_column($counted, 'value2')) / count($counted),
        'n' => count($counted), 'days' => count(array_unique(array_map(fn ($r) => local_time($r['recorded_at'], 'Y-m-d'), $counted)))] : null;
    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $days, 'average' => $average,
        'done' => $today > $end || ($average && $average['days'] >= BP_SESSION_DAYS - 1)];
}

/** Combined hormonal contraception with raised blood pressure (UK guidance on contraception). */
function bp_contraception_warning(array $user, array $meds): ?string
{
    $chc = array_filter($meds, fn ($m) => preg_match(COMBINED_HC_PATTERN, $m['name'] . ' ' . $m['reason']));
    if (!$chc) {
        return null;
    }
    $recent = bp_readings((int) $user['id'], gmdate('Y-m-d H:i:s', time() - 60 * 86400));
    if (count($recent) < 3) {
        return null;
    }
    $sys = array_sum(array_column($recent, 'value1')) / count($recent);
    $dia = array_sum(array_column($recent, 'value2')) / count($recent);
    return $sys >= 140 || $dia >= 90
        ? 'Your recent blood pressure averages ' . (int) round($sys) . '/' . (int) round($dia) . ' and you take ' . implode(', ', array_map(fn ($m) => $m['name'], $chc))
            . '. Combined hormonal contraception may not be suitable with raised blood pressure: mention it to your GP or sexual health clinic.'
        : null;
}

/** Import a monitor's CSV export (Omron and others): date, time, systolic, diastolic, pulse, irregular. */
function import_bp_csv(int $userId, string $path, DateTimeZone $tz): array|string
{
    if (filesize($path) > 5 * 1024 * 1024) {
        return 'That file is too big (over 5 MB) to be a blood pressure export.';
    }
    $rows = csv_records($path);
    if (!$rows) {
        return "That file doesn't look like a CSV export from a blood pressure monitor.";
    }
    $find = function (array $row, array $words) {
        foreach (array_keys($row) as $k) {
            foreach ($words as $w) {
                if (stripos($k, $w) !== false) {
                    return $k;
                }
            }
        }
        return null;
    };
    $cols = ['sys' => $find($rows[0], ['sys', 'sbp']), 'dia' => $find($rows[0], ['dia', 'dbp']), 'pulse' => $find($rows[0], ['pulse', 'heart', 'hr', 'bpm']),
        'date' => $find($rows[0], ['date', 'time stamp', 'timestamp', 'measured']), 'time' => $find($rows[0], ['time']), 'irr' => $find($rows[0], ['irregular', 'ihb', 'afib', 'arrhythm'])];
    if (!$cols['sys'] || !$cols['dia'] || !$cols['date']) {
        return "Couldn't find systolic, diastolic and date columns in that file.";
    }
    $out = [];
    foreach ($rows as $r) {
        $when = trim($r[$cols['date']] . ($cols['time'] && $cols['time'] !== $cols['date'] ? ' ' . $r[$cols['time']] : ''));
        $ts = export_date($when, $tz);
        $sys = (int) $r[$cols['sys']];
        $dia = (int) $r[$cols['dia']];
        if (!$ts || $sys < 50 || $sys > 260 || $dia < 30 || $dia > 160) {
            continue;
        }
        $pulse = $cols['pulse'] ? (int) $r[$cols['pulse']] : null;
        $irr = $cols['irr'] && preg_match('/^(1|yes|true|y|detected)/i', trim((string) $r[$cols['irr']]));
        $row = connector_row('bp_csv', 'blood_pressure', [$sys, $dia, $pulse ?: null], $ts, "$ts|$sys|$dia");
        $row['notes'] = $irr ? 'Irregular heartbeat' : null;
        $out[] = $row;
    }
    return $out ? save_health_rows($userId, $out, 'bp_csv') : 'No readings found in that file.';
}

/** Actions posted from care.php?area=bp ('bp_...'); always redirects. */
function bp_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=bp';
    $save = fn (array $cols) => q('INSERT INTO bp_profiles (user_id, ' . implode(', ', array_keys($cols)) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", array_keys($cols))), [$uid, ...array_values($cols)]);
    switch (post('action')) {
        case 'bp_profile':
            $sys = (int) post('target_sys');
            $dia = (int) post('target_dia');
            $save(['target_sys' => $sys >= 100 && $sys <= 180 ? $sys : null, 'target_dia' => $dia >= 50 && $dia <= 110 ? $dia : null,
                'monitor' => trim(mb_substr(post('monitor'), 0, 100)) ?: null, 'cuff' => in_array(post('cuff'), ['small', 'standard', 'large', 'xl'], true) ? post('cuff') : null,
                'validated' => post('validated') === '1' ? 1 : 0, 'sick_day' => post('sick_day') === '1' ? 1 : 0]);
            flash('success', 'Saved.');
            redirect($back . '#bp-about');
        case 'bp_session':
            $save(['session_start' => post('stop') === '1' ? null : $today->format('Y-m-d')]);
            redirect($back . '#bp-session');
        case 'bp_reading':
            $msgs = [];
            foreach ([1, 2] as $n) {
                $sys = (int) post("sys$n");
                $dia = (int) post("dia$n");
                if ($sys < 50 || $sys > 260 || $dia < 30 || $dia > 160) {
                    continue;
                }
                $pulse = (int) post("pulse$n");
                q("INSERT INTO health_readings (user_id, metric, value1, value2, value3, context, notes, recorded_at) VALUES (?, 'blood_pressure', ?, ?, ?, ?, ?, ?)",
                    [$uid, $sys, $dia, $pulse >= 20 && $pulse <= 250 ? $pulse : null, post('session') === '1' ? 'Home monitoring' : 'Sitting',
                        post("irregular$n") === '1' ? 'Irregular heartbeat' : null, gmdate('Y-m-d H:i:s', time() - ($n === 1 && post('sys2') !== '' ? 60 : 0))]);
                if ($a = bp_advice($sys, $dia)) {
                    $msgs[$a[0]] = $a[1];
                }
                if (post("irregular$n") === '1') {
                    $msgs['irr'] = 'Your monitor flagged an irregular heartbeat. One flag can happen; if it keeps happening, tell your GP (it can be a sign of atrial fibrillation).';
                }
            }
            if (isset($msgs['bad'])) {
                flash('error', $msgs['bad']);
            } elseif ($msgs) {
                flash('error', implode(' ', $msgs));
            } else {
                flash('success', 'Saved.');
            }
            redirect($back . (post('session') === '1' ? '#bp-session' : '#bp-readings'));
        case 'bp_side_effect':
            $med = find_medication((int) post('medication_id'), $uid);
            $effect = BP_SIDE_EFFECTS[post('effect')][0] ?? null;
            if ($med && $effect) {
                q("INSERT INTO med_notes (user_id, medication_id, kind, note, severity, created_at) VALUES (?, ?, 'side_effect', ?, ?, UTC_TIMESTAMP())",
                    [$uid, $med['id'], $effect, is_numeric(post('severity')) ? max(0, min(10, (int) post('severity'))) : null]);
                flash('success', "Noted: $effect with {$med['name']}. Mention it at your next review" . (post('effect') === 'cough' ? ': a different medicine (such as an ARB) often helps.' : '.'));
            }
            redirect($back . '#bp-meds');
        case 'bp_import':
            $file = $_FILES['file'] ?? null;
            $result = $file && ($file['error'] ?? 1) === UPLOAD_ERR_OK ? import_bp_csv($uid, $file['tmp_name'], user_tz($user)) : 'Choose the CSV file your monitor or its app exported.';
            is_string($result) ? flash('error', $result) : flash('success', "Imported: {$result['added']} new and {$result['updated']} updated readings.");
            redirect($back . '#bp-readings');
    }
    redirect($back);
}

/** The blood pressure sections at the top of care.php?area=bp. */
function bp_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $p = bp_profile($uid);
    [$tSys, $tDia, $tWhy] = bp_target($user, $p);
    $session = bp_session($user, $p, $today);
    $readings = bp_readings($uid, gmdate('Y-m-d H:i:s', time() - 90 * 86400));
    $meds = user_medications($uid);
    $bpMeds = array_filter($meds, fn ($m) => bp_class($m['name']));
    $classes = array_unique(array_map(fn ($m) => bp_class($m['name']), $bpMeds));
    $am = array_filter($readings, fn ($r) => (int) local_time($r['recorded_at'], 'G') < 13);
    $pm = array_filter($readings, fn ($r) => (int) local_time($r['recorded_at'], 'G') >= 13);
    $avg = fn (array $rs) => $rs ? (int) round(array_sum(array_column($rs, 'value1')) / count($rs)) . '/' . (int) round(array_sum(array_column($rs, 'value2')) / count($rs)) : null;
    $irregular = count(array_filter($readings, fn ($r) => stripos((string) $r['notes'], 'irregular') !== false));
    $sideEffects = $bpMeds ? q("SELECT n.*, m.name FROM med_notes n JOIN medications m ON m.id = n.medication_id WHERE n.user_id = ? AND n.kind = 'side_effect' AND n.medication_id IN ("
        . implode(',', array_map('intval', array_column($bpMeds, 'id'))) . ') ORDER BY n.created_at DESC LIMIT 8', [$uid])->fetchAll() : [];
    $recentChange = q("SELECT c.*, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id WHERE c.user_id = ? AND c.kind IN ('started', 'increased', 'restarted') AND c.effective_date >= ?
        ORDER BY c.effective_date DESC", [$uid, $today->modify('-21 days')->format('Y-m-d')])->fetchAll();
    $recentChange = array_values(array_filter($recentChange, fn ($c) => in_array(bp_class($c['name']), ['acei', 'arb'], true) || preg_match('/spironolactone|eplerenone/i', $c['name'])));
    $todayYmd = $today->format('Y-m-d');
    ?>
<?php foreach ($recentChange as $c): ?>
<p class="flash flash-info">You started or increased <?= h($c['name']) ?> on <?= h(date('j M', strtotime($c['effective_date']))) ?>: a blood test for kidney function and potassium is usually done 1 to 2 weeks after. Check one's booked.</p>
<?php endforeach; ?>
<section class="card" id="bp-session">
    <h2>Home monitoring <?= help_link('blood-pressure', 'home-monitoring', 'home monitoring') ?></h2>
    <p class="small">Your target at home is <strong>below <?= $tSys ?>/<?= $tDia ?></strong> (<?= h($tWhy) ?>). The usual way to check it, from NICE: sit quietly for 5 minutes, then take 2 readings a minute apart, morning and evening, for 4 to 7 days. The first day is left out, and the rest averaged.</p>
    <?php if ($session): ?>
    <div class="bp-days">
        <?php for ($i = 0; $i < BP_SESSION_DAYS; $i++): $d = (new DateTimeImmutable($session['start']))->modify("+$i days")->format('Y-m-d'); $day = $session['days'][$d] ?? []; ?>
        <div class="bp-day<?= $d === $todayYmd ? ' today' : '' ?><?= $i === 0 ? ' first' : '' ?>"><strong><?= h(date('D', strtotime($d))) ?></strong>
            <span class="<?= !empty($day['morning']) ? 'done' : '' ?>">AM <?= count($day['morning'] ?? []) ?></span><span class="<?= !empty($day['evening']) ? 'done' : '' ?>">PM <?= count($day['evening'] ?? []) ?></span></div>
        <?php endfor; ?>
    </div>
    <?php if ($session['average']): $ok = $session['average']['sys'] < $tSys && $session['average']['dia'] < $tDia; ?>
    <p class="flash <?= $ok ? 'flash-success' : 'flash-warning' ?>">Average so far (<?= $session['average']['n'] ?> readings over <?= $session['average']['days'] ?> days, leaving out day 1):
        <strong><?= (int) round($session['average']['sys']) ?>/<?= (int) round($session['average']['dia']) ?></strong>, <?= $ok ? 'below' : 'above' ?> your target of <?= $tSys ?>/<?= $tDia ?>.
        <?= $session['done'] ? ($ok ? 'Well done.' : 'Share this with your GP or practice nurse.') : 'Keep going to the end of the week.' ?></p>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" class="stack bp-entry"><?= csrf_field() ?><input type="hidden" name="action" value="bp_reading"><input type="hidden" name="session" value="<?= $session && !$session['done'] ? 1 : 0 ?>">
        <?php foreach ([1, 2] as $n): ?>
        <fieldset><legend>Reading <?= $n ?><?= $n === 2 ? ' <span class="muted small">(a minute later)</span>' : '' ?></legend>
            <div class="inline-form">
                <label>Top <input type="number" name="sys<?= $n ?>" min="50" max="260" inputmode="numeric" class="count-input"<?= $n === 1 ? ' required' : '' ?>></label>
                <label>Bottom <input type="number" name="dia<?= $n ?>" min="30" max="160" inputmode="numeric" class="count-input"<?= $n === 1 ? ' required' : '' ?>></label>
                <label>Pulse <input type="number" name="pulse<?= $n ?>" min="20" max="250" inputmode="numeric" class="count-input"></label>
                <label class="check"><input type="checkbox" name="irregular<?= $n ?>" value="1"> Irregular heartbeat symbol</label>
            </div>
            <?php if ($n === 1): ?><button class="btn btn-small" type="button" data-timer-quick="60">1-minute timer</button><?php endif; ?>
        </fieldset>
        <?php endforeach; ?>
        <section class="card timer" data-timer hidden>
            <div class="timer-ring" aria-hidden="true"><svg viewBox="0 0 120 120"><circle class="timer-track" cx="60" cy="60" r="52"/><circle class="timer-fill" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="100 100" data-timer-ring/></svg>
                <span class="timer-time" data-timer-time>1:00</span></div>
            <p class="sr" aria-live="polite" data-timer-status></p>
            <div hidden><button type="button" data-timer-set="60"></button><input data-timer-custom></div>
            <span class="inline-actions"><button class="btn btn-small" type="button" data-timer-start hidden>Start</button><button class="btn btn-small" type="button" data-timer-pause hidden>Pause</button><button class="btn btn-small" type="button" data-timer-reset hidden>Reset</button></span>
        </section>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bp_session">
        <?php if ($session && !$session['done']): ?><input type="hidden" name="stop" value="1"><button class="btn-link small" type="submit">Stop this week's monitoring</button>
        <?php else: ?><button class="btn btn-small" type="submit"><?= $session ? 'Start another week of home monitoring' : 'Start a week of home monitoring' ?></button><?php endif; ?></form>
    <?php endif; ?>
</section>

<section class="card" id="bp-readings">
    <h2>Readings</h2>
    <?php if ($readings): ?>
    <div class="tiles">
        <?php if ($avg($am)): ?><div class="tile"><span class="tile-label">Morning average</span><span class="tile-value"><?= $avg($am) ?></span><span class="tile-meta">last 90 days, <?= count($am) ?> readings</span></div><?php endif; ?>
        <?php if ($avg($pm)): ?><div class="tile"><span class="tile-label">Evening average</span><span class="tile-value"><?= $avg($pm) ?></span><span class="tile-meta"><?= count($pm) ?> readings</span></div><?php endif; ?>
        <?php if ($irregular): ?><div class="tile"><span class="tile-label">Irregular heartbeat flags</span><span class="tile-value warn"><?= $irregular ?></span><span class="tile-meta">tell your GP if it keeps happening</span></div><?php endif; ?>
    </div>
    <?php $ts = fn ($r) => strtotime($r['recorded_at'] . ' UTC'); ?>
    <?= svg_line_chart([['label' => 'Top (systolic)', 'unit' => 'mmHg', 'points' => array_map(fn ($r) => [$ts($r), (float) $r['value1']], $readings)],
        ['label' => 'Bottom (diastolic)', 'unit' => 'mmHg', 'points' => array_map(fn ($r) => [$ts($r), (float) $r['value2']], $readings)]],
        time() - 90 * 86400, time(), 'Blood pressure over 90 days', [], medication_change_markers($uid, fn ($name) => bp_class($name) !== null)) ?>
    <p class="muted small">Dashed lines: changes to blood pressure medicines. Your target: below <?= $tSys ?>/<?= $tDia ?>.</p>
    <?php else: ?><p class="small">No readings yet. Use the form above, <a href="<?= h(url('readings.php?metric=blood_pressure')) ?>">Readings</a>, or a connected monitor.</p><?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"><summary>Import from your monitor (CSV)</summary>
        <form method="post" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="bp_import">
            <label>Export file <input type="file" name="file" accept=".csv,text/csv" required></label><button class="btn btn-small" type="submit">Import</button></form>
        <p class="muted small">Most monitor apps (such as Omron Connect) can export a CSV with the date, systolic, diastolic and pulse. Importing again never creates duplicates.
            Withings monitors can connect directly under <a href="<?= h(url('connections.php')) ?>">Connect apps and devices</a>.</p>
    </details>
    <?php endif; ?>
</section>

<section class="card" id="bp-meds">
    <h2>Medicines</h2>
    <?php if (!$bpMeds): ?><p class="small">No blood pressure medicines recorded. <a href="<?= h(url('medication_edit.php')) ?>">Add one</a> for reminders and to see changes on the chart.</p><?php endif; ?>
    <?php foreach ($bpMeds as $m): ?>
    <div class="logline"><span><?= med_dot($m['colour']) ?> <strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> <span class="muted small"><?= h(BP_CLASSES[bp_class($m['name'])][0]) ?></span></span>
        <?php if ($canEdit): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="bp_side_effect"><input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>">
            <select name="effect" aria-label="Side effect"><?php foreach (BP_SIDE_EFFECTS as $k => [$l, $for]): ?><option value="<?= $k ?>"<?= selected($for && in_array(bp_class($m['name']), $for, true)) ?>><?= h($l) ?></option><?php endforeach; ?></select>
            <button class="btn btn-small" type="submit">Note a side effect</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($sideEffects): ?><p class="small">Noted: <?= h(implode(' · ', array_map(fn ($n) => $n['note'] . ' (' . $n['name'] . ', ' . local_time($n['created_at'], 'j M') . ')', $sideEffects))) ?></p><?php endif; ?>
    <?php if (in_array('acei', $classes, true)): ?><p class="muted small">A dry, tickly cough is a common side effect of ACE inhibitors (the "-pril" medicines): if you get one, ask about switching.</p><?php endif; ?>
    <?php if (in_array('ccb', $classes, true)): ?><p class="muted small">Ankle swelling is common with amlodipine and similar medicines: mention it at your review.</p><?php endif; ?>
    <?php if ($p['sick_day'] && array_intersect($classes, ['acei', 'arb', 'diuretic'])): ?>
    <p class="small"><strong>Sick-day rules (as your clinician advised):</strong> if you have vomiting, diarrhoea, a fever with sweats and shaking, or can't drink enough, pause
        <?= h(implode(', ', array_map(fn ($m) => $m['name'], array_filter($bpMeds, fn ($m) => in_array(bp_class($m['name']), ['acei', 'arb', 'diuretic'], true))))) ?>
        and restart 24 to 48 hours after you're eating and drinking normally. If you're not better in 48 hours, contact your GP.</p>
    <?php endif; ?>
</section>

<section class="card" id="bp-about">
    <h2>Your monitor and target</h2>
    <?php if (!$p['validated']): ?><p class="small">Use a validated upper-arm monitor: the British and Irish Hypertension Society keeps a list of tested monitors. The cuff size matters too.</p><?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="bp_profile">
        <div class="grid-2">
            <label>Monitor <input name="monitor" maxlength="100" value="<?= h((string) $p['monitor']) ?>" placeholder="e.g. Omron M3"></label>
            <label>Cuff size <select name="cuff"><option value="">&ndash;</option><?php foreach (['small' => 'Small', 'standard' => 'Standard', 'large' => 'Large', 'xl' => 'Extra large'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($p['cuff'] === $k) ?>><?= $l ?></option><?php endforeach; ?></select></label>
            <label><span>Target from your clinician <span class="muted small">(leave empty for <?= $tWhy === 'set by your clinician' ? 'the usual' : 'the usual ' . $tSys . '/' . $tDia ?>)</span></span>
                <span class="range-pair"><input type="number" name="target_sys" min="100" max="180" class="count-input" value="<?= h((string) $p['target_sys']) ?>"> /
                <input type="number" name="target_dia" min="50" max="110" class="count-input" value="<?= h((string) $p['target_dia']) ?>"></span></label>
        </div>
        <label class="check"><input type="checkbox" name="validated" value="1"<?= checked((bool) $p['validated']) ?>> My monitor is on the validated list</label>
        <label class="check"><input type="checkbox" name="sick_day" value="1"<?= checked((bool) $p['sick_day']) ?>> My clinician has given me sick-day rules for my blood pressure medicines</label>
        <span class="inline-actions"><button class="btn btn-small" type="submit">Save</button><a class="btn btn-small" href="<?= h(url('bp_summary.php')) ?>">Clinician summary</a></span>
    </form>
    <?php endif; ?>
</section>
    <?php
}
