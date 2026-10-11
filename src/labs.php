<?php
declare(strict_types=1);

/*
 * Lab results (shown in Care > Blood tests): numbers in UK units, trend charts, and ranges the
 * person (or their clinic) chooses, shown alongside the lab's own range. Lab ranges are for
 * the sex on someone's record, which is often meaningless on hormones, so Pillbox never
 * judges a result: it only shows it against the ranges given.
 * Timing matters for hormone levels (e.g. injections are usually checked just before the next
 * one, the "trough"), so each result can record that and the time since the last dose.
 */

// key => [label, unit, group]
const LAB_TESTS = [
    'oestradiol'    => ['Oestradiol', 'pmol/L', 'Hormones'],
    'testosterone'  => ['Testosterone', 'nmol/L', 'Hormones'],
    'shbg'          => ['SHBG', 'nmol/L', 'Hormones'],
    'lh'            => ['LH', 'IU/L', 'Hormones'],
    'fsh'           => ['FSH', 'IU/L', 'Hormones'],
    'prolactin'     => ['Prolactin', 'mIU/L', 'Hormones'],
    'progesterone'  => ['Progesterone', 'nmol/L', 'Hormones'],
    'tsh'           => ['TSH', 'mU/L', 'Thyroid'],
    'free_t4'       => ['Free T4', 'pmol/L', 'Thyroid'],
    'free_t3'       => ['Free T3', 'pmol/L', 'Thyroid'],
    'tpo'           => ['Thyroid (TPO) antibodies', 'IU/mL', 'Thyroid'],
    'haemoglobin'   => ['Haemoglobin', 'g/L', 'Blood count'],
    'haematocrit'   => ['Haematocrit', 'L/L', 'Blood count'],
    'platelets'     => ['Platelets', '×10⁹/L', 'Blood count'],
    'ferritin'      => ['Ferritin', 'µg/L', 'Blood count'],
    'alt'           => ['ALT (liver)', 'U/L', 'Liver and kidneys'],
    'alp'           => ['ALP', 'U/L', 'Liver and kidneys'],
    'creatinine'    => ['Creatinine', 'µmol/L', 'Liver and kidneys'],
    'egfr'          => ['eGFR', 'mL/min/1.73m²', 'Liver and kidneys'],
    'potassium'     => ['Potassium', 'mmol/L', 'Liver and kidneys'],
    'sodium'        => ['Sodium', 'mmol/L', 'Liver and kidneys'],
    'cholesterol'   => ['Total cholesterol', 'mmol/L', 'Heart and metabolism'],
    'ldl'           => ['LDL cholesterol', 'mmol/L', 'Heart and metabolism'],
    'hdl'           => ['HDL cholesterol', 'mmol/L', 'Heart and metabolism'],
    'triglycerides' => ['Triglycerides', 'mmol/L', 'Heart and metabolism'],
    'hba1c'         => ['HbA1c', 'mmol/mol', 'Heart and metabolism'],
    'vitamin_d'     => ['Vitamin D', 'nmol/L', 'Other'],
    'b12'           => ['Vitamin B12', 'ng/L', 'Other'],
    'psa'           => ['PSA', 'µg/L', 'Other'],
    'crp'           => ['CRP', 'mg/L', 'Other'],
    'lithium'       => ['Lithium', 'mmol/L', 'Other'],
    'inr'           => ['INR', '', 'Other'],
];

const LAB_TIMING = ['trough' => 'Just before a dose (trough)', 'peak' => 'Soon after a dose (peak)', 'mid' => 'Between doses', 'unknown' => 'Not sure / not relevant'];

function lab_results(int $userId): array
{
    return q('SELECT * FROM lab_results WHERE user_id = ? ORDER BY taken_at', [$userId])->fetchAll();
}

/** Ranges chosen by the person or their clinic: [test => row]. */
function lab_targets(int $userId): array
{
    $out = [];
    foreach (q('SELECT * FROM lab_targets WHERE user_id = ?', [$userId]) as $t) {
        $out[$t['test']] = $t;
    }
    return $out;
}

/** "3 days 4 hours" between the last dose of a medicine and a blood test. */
function since_dose_label(?int $hours): string
{
    if ($hours === null) {
        return '';
    }
    return $hours < 48 ? "$hours h after the last dose" : intdiv($hours, 24) . ' days ' . ($hours % 24 ? ($hours % 24) . ' h ' : '') . 'after the last dose';
}

/** Where a value sits against a range, in neutral words ("in your target range" / "above your target range"). */
function lab_position(float $v, ?string $low, ?string $high, string $which): ?string
{
    if ($low === null && $high === null) {
        return null;
    }
    if ($low !== null && $v < (float) $low) {
        return "below $which";
    }
    if ($high !== null && $v > (float) $high) {
        return "above $which";
    }
    return "within $which";
}

/** Actions posted from the lab sections of care.php?area=bloods ('lab_...'); always redirects. */
function labs_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=bloods#labs';
    $num = fn (string $f) => is_numeric(post($f)) ? (float) post($f) : null;
    switch (post('action')) {
        case 'lab_add':
            $test = post('test');
            $value = $num('value');
            $at = local_to_utc((parse_date(post('date')) ?: $today)->format('Y-m-d') . ' ' . (preg_match('/^\d\d:\d\d$/', post('time')) ? post('time') : '09:00'));
            if (!isset(LAB_TESTS[$test]) || $value === null || $value < 0 || $value > 1000000 || !$at || $at > utc_now()) {
                flash('error', 'Choose the test and enter the result and when the blood was taken.');
                redirect($back);
            }
            // Time since the last dose of the chosen medicine, from the dose log.
            $med = post('medication_id') !== '' ? find_medication((int) post('medication_id'), $uid) : null;
            $hours = null;
            if ($med) {
                $last = q("SELECT MAX(taken_at) FROM dose_logs WHERE medication_id = ? AND status = 'taken' AND taken_at <= ?", [$med['id'], $at])->fetchColumn();
                $hours = $last ? intdiv(strtotime($at) - strtotime($last), 3600) : null;
            }
            q('INSERT INTO lab_results (user_id, test, value, unit, taken_at, timing, medication_id, hours_since_dose, lab_low, lab_high, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $uid, $test, $value, LAB_TESTS[$test][1], $at, isset(LAB_TIMING[post('timing')]) ? post('timing') : 'unknown', $med['id'] ?? null, $hours,
                $num('lab_low'), $num('lab_high'), trim(mb_substr(post('notes'), 0, 300)) ?: null,
            ]);
            flash('success', LAB_TESTS[$test][0] . ' result saved.');
            redirect('care.php?area=bloods#lab-' . $test);
        case 'lab_delete':
            q('DELETE FROM lab_results WHERE id = ? AND user_id = ?', [(int) post('result_id'), $uid]);
            redirect($back);
        case 'lab_target':
            $test = post('test');
            if (isset(LAB_TESTS[$test])) {
                if ($num('low') === null && $num('high') === null) {
                    q('DELETE FROM lab_targets WHERE user_id = ? AND test = ?', [$uid, $test]);
                } else {
                    q('INSERT INTO lab_targets (user_id, test, low, high, note) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE low = VALUES(low), high = VALUES(high), note = VALUES(note)',
                        [$uid, $test, $num('low'), $num('high'), trim(mb_substr(post('note'), 0, 150)) ?: null]);
                }
                flash('success', 'Target range saved.');
            }
            redirect('care.php?area=bloods#lab-' . $test);
    }
    redirect($back);
}

/** The "Results and trends" section of care.php?area=bloods. */
function labs_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $results = lab_results($uid);
    $targets = lab_targets($uid);
    $byTest = [];
    foreach ($results as $r) {
        $byTest[$r['test']][] = $r;
    }
    $canEdit = share_can('care', 'edit');
    $meds = array_filter(user_medications($uid), fn ($m) => !$m['as_needed']);
    ?>
<section class="card" id="labs">
    <h2>Results and trends</h2>
    <p class="muted small">Numbers from your blood tests, in UK units. Lab reports compare against the range for the sex on your record, which may not fit you
        (for example on hormones), so you can add a target range from your clinic as well. Pillbox shows where results sit; your clinic interprets them.</p>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $results ? '' : ' open' ?>><summary>Add a result</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="lab_add">
            <div class="grid-2">
                <label>Test <select name="test" required><option value="">Choose&hellip;</option>
                    <?php $group = null; foreach (LAB_TESTS as $k => [$label, $unit, $g]): if ($g !== $group): echo $group ? '</optgroup>' : ''; $group = $g; ?><optgroup label="<?= h($g) ?>"><?php endif; ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?><?= $unit ? ' (' . h($unit) . ')' : '' ?></option>
                    <?php endforeach; ?></optgroup></select></label>
                <label>Result <input type="number" name="value" step="any" min="0" required inputmode="decimal"></label>
                <label>Blood taken on <input type="date" name="date" max="<?= h($today->format('Y-m-d')) ?>" value="<?= h($today->format('Y-m-d')) ?>"></label>
                <label>at <input type="time" name="time" value="09:00"></label>
                <label>Timing <select name="timing"><?php foreach (LAB_TIMING as $k => $l): ?><option value="<?= $k ?>"<?= selected($k === 'unknown') ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <?php if ($meds): ?>
                <label><span>Related medicine <span class="muted small">(works out the time since your last dose)</span></span>
                    <select name="medication_id"><option value="">&ndash;</option><?php foreach ($meds as $m): ?><option value="<?= (int) $m['id'] ?>"><?= h($m['name'] . ' ' . $m['strength']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <label><span>Lab's range <span class="muted small">(optional, from the report)</span></span>
                    <span class="range-pair"><input type="number" name="lab_low" step="any" aria-label="Lowest" class="count-input"> to <input type="number" name="lab_high" step="any" aria-label="Highest" class="count-input"></span></label>
                <label>Notes <input name="notes" maxlength="300"></label>
            </div>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save result</button></span>
        </form>
    </details>
    <?php endif; ?>

    <?php foreach ($byTest as $test => $rows): [$label, $unit] = LAB_TESTS[$test] ?? [$test, '']; $t = $targets[$test] ?? null; $latest = end($rows);
        $labLow = $latest['lab_low']; $labHigh = $latest['lab_high'];
        $bands = [];
        if ($labLow !== null || $labHigh !== null) {
            $bands[] = ['low' => $labLow, 'high' => $labHigh, 'class' => 'band-lab', 'label' => "Lab's range"];
        }
        if ($t) {
            $bands[] = ['low' => $t['low'], 'high' => $t['high'], 'class' => 'band-target', 'label' => 'Your target range'];
        }
        $points = array_map(fn ($r) => [strtotime($r['taken_at'] . ' UTC'), (float) $r['value']], $rows);
        $first = $points[0][0]; $lastTs = end($points)[0];
        $markers = in_array($test, ['tsh', 'free_t4', 'free_t3'], true) ? thyroid_dose_markers($uid) : []; ?>
    <div class="lab-test" id="lab-<?= h($test) ?>">
        <h3><?= h($label) ?> <span class="muted small"><?= h($unit) ?></span></h3>
        <p class="small">Latest: <strong><?= h(num($latest['value'])) ?> <?= h($unit) ?></strong> on <?= h(local_time($latest['taken_at'], 'j M Y')) ?>
            <?= ($pos = lab_position((float) $latest['value'], $t['low'] ?? null, $t['high'] ?? null, 'your target range')) ? ' · ' . h($pos) : '' ?>
            <?= ($pos = lab_position((float) $latest['value'], $labLow, $labHigh, "the lab's range")) ? ' · ' . h($pos) : '' ?></p>
        <?php if (count($points) > 1): ?><?= svg_line_chart([['label' => $label, 'unit' => $unit, 'points' => $points]], $first - 86400 * 7, $lastTs + 86400 * 7, "$label results", $bands, $markers) ?>
        <p class="muted small chart-key"><?= $t ? '<span class="key key-target"></span> your target range ' : '' ?><?= $labLow !== null || $labHigh !== null ? '<span class="key key-lab"></span> the lab\'s range' : '' ?></p><?php endif; ?>
        <details class="q-edit"><summary>All results (<?= count($rows) ?>)<?= $t ? '' : ' and target range' ?></summary>
            <ul class="plain"><?php foreach (array_reverse($rows) as $r): ?>
                <li class="logline"><span><strong><?= h(local_time($r['taken_at'], 'j M Y, H:i')) ?></strong> · <?= h(num($r['value'])) ?> <?= h($unit) ?>
                    <span class="muted small"><?= $r['timing'] !== 'unknown' ? h(LAB_TIMING[$r['timing']]) : '' ?><?= $r['hours_since_dose'] !== null ? ' · ' . h(since_dose_label((int) $r['hours_since_dose'])) : '' ?>
                        <?= $r['lab_low'] !== null || $r['lab_high'] !== null ? " · lab range " . h(num($r['lab_low'] ?? '–')) . '–' . h(num($r['lab_high'] ?? '–')) : '' ?><?= $r['notes'] ? ' · ' . h($r['notes']) : '' ?></span></span>
                    <?php if ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lab_delete"><input type="hidden" name="result_id" value="<?= (int) $r['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form><?php endif; ?></li>
            <?php endforeach; ?></ul>
            <?php if ($canEdit): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="lab_target"><input type="hidden" name="test" value="<?= h($test) ?>">
                <label>Target range <span class="range-pair"><input type="number" name="low" step="any" aria-label="Lowest" class="count-input" value="<?= h((string) ($t['low'] ?? '')) ?>"> to
                    <input type="number" name="high" step="any" aria-label="Highest" class="count-input" value="<?= h((string) ($t['high'] ?? '')) ?>"></span></label>
                <label>From <input name="note" maxlength="150" placeholder="e.g. my clinic" value="<?= h((string) ($t['note'] ?? '')) ?>"></label>
                <button class="btn btn-small" type="submit">Save range</button>
            </form>
            <?php endif; ?>
        </details>
    </div>
    <?php endforeach; ?>
</section>
    <?php
}
