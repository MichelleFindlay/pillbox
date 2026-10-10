<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
$todayYmd = $today->format('Y-m-d');

$id = (int) ($_GET['id'] ?? 0);
$med = $id ? find_medication($id, $uid) : null;
if ($id && !$med) {
    not_found();
}

$fields = ['name', 'dmd_code', 'strength', 'form', 'dose_unit', 'colour', 'reason', 'instructions', 'prescriber', 'notes',
    'as_needed', 'prn_dose_amount', 'prn_max_daily', 'stock_count', 'low_stock_days', 'start_date', 'end_date'];
$form = $med ?? ['form' => 'tablet', 'dose_unit' => 'tablet', 'colour' => MED_COLOURS[0], 'as_needed' => 0,
    'low_stock_days' => 7, 'start_date' => $todayYmd];
$rows = $med
    ? schedules_for_editing(current_schedules((int) $med['id'], $todayYmd))
    : [editor_row(['dose_time' => '08:00', 'dose_amount' => 1, 'days_mask' => EVERY_DAY, 'valid_from' => null, 'valid_to' => null], [], 'same', [])];
$errors = [];

if (is_post()) {
    require_post();
    foreach ($fields as $f) {
        $form[$f] = post($f);
    }
    $form['as_needed'] = isset($_POST['as_needed']) ? 1 : 0;

    // ---- Validate medication fields ----
    if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
        $errors[] = 'Enter the medication name (up to 150 characters).';
    }
    if (!in_array($form['form'], MED_FORMS, true)) {
        $errors[] = 'Choose a valid form.';
    }
    if (!in_array($form['dose_unit'], DOSE_UNITS, true)) {
        $errors[] = 'Choose a valid dose unit.';
    }
    if (!preg_match('/^\d{6,18}$/', $form['dmd_code'])) {
        $form['dmd_code'] = '';
    }
    if (!in_array($form['colour'], MED_COLOURS, true)) {
        $form['colour'] = 'grey';
    }
    foreach (['strength' => 60, 'reason' => 150, 'instructions' => 255, 'prescriber' => 150, 'notes' => 5000] as $f => $max) {
        if (mb_strlen($form[$f]) > $max) {
            $errors[] = ucfirst($f) . " is too long (max $max characters).";
        }
    }
    foreach (['prn_dose_amount' => 'Dose amount', 'prn_max_daily' => 'Daily maximum', 'stock_count' => 'Stock'] as $f => $label) {
        if ($form[$f] !== '' && (!is_numeric($form[$f]) || (float) $form[$f] < 0 || (float) $form[$f] > 99999)) {
            $errors[] = "$label must be a positive number.";
        }
    }
    if (!ctype_digit((string) $form['low_stock_days']) || (int) $form['low_stock_days'] > 365) {
        $form['low_stock_days'] = 7;
    }
    if (!parse_date($form['start_date'])) {
        $errors[] = 'Enter a valid start date.';
    }
    if ($form['end_date'] !== '' && (!parse_date($form['end_date']) || $form['end_date'] < $form['start_date'])) {
        $errors[] = 'The end date must be a valid date on or after the start date.';
    }

    // ---- Validate dose schedule rows ----
    // As-needed medicines don't use dose times, so ignore whatever is in the (hidden) rows.
    $submitted = is_array($_POST['schedules'] ?? null) && !$form['as_needed'] ? $_POST['schedules'] : [];
    if (!$form['as_needed']) {
        $rows = [];
    }
    foreach ($submitted as $r) {
        if (!is_array($r)) {
            continue;
        }
        $time = trim((string) ($r['time'] ?? ''));
        $mode = ($r['mode'] ?? '') === 'daily' ? 'daily' : 'same';
        $amount = trim((string) ($r['amount'] ?? ''));
        $dayAmounts = [];
        foreach (WEEKDAYS as $bit => $_) {
            $dayAmounts[$bit] = trim((string) ($r['day_amount'][$bit] ?? ''));
        }
        $hasDayAmounts = implode('', $dayAmounts) !== '';
        if ($time === '' && $amount === '' && !$hasDayAmounts) {
            continue;
        }
        $mask = 0;
        foreach ((array) ($r['days'] ?? []) as $bit) {
            $mask |= ((int) $bit) & EVERY_DAY;
        }
        $row = [
            'ids' => array_values(array_filter(array_map('intval', explode(',', (string) ($r['ids'] ?? ''))))),
            'dose_time' => $time,
            'mode' => $mode,
            'dose_amount' => $amount,
            'days_mask' => $mask,
            'day_amounts' => $dayAmounts,
            'valid_from' => trim((string) ($r['valid_from'] ?? '')) ?: null,
            'valid_to' => trim((string) ($r['valid_to'] ?? '')) ?: null,
        ];
        $n = count($rows) + 1;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors[] = "Dose $n: enter a time.";
        }
        if ($mode === 'same') {
            if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 10000) {
                $errors[] = "Dose $n: enter an amount greater than zero.";
            }
            if ($mask === 0) {
                $errors[] = "Dose $n: choose at least one day.";
            }
        } else {
            foreach ($dayAmounts as $bit => $a) {
                if ($a !== '' && (!is_numeric($a) || (float) $a < 0 || (float) $a > 10000)) {
                    $errors[] = "Dose $n: the amount for " . WEEKDAYS[$bit] . ' must be a number.';
                }
            }
            if (!array_filter($dayAmounts, fn ($a) => is_numeric($a) && (float) $a > 0)) {
                $errors[] = "Dose $n: enter an amount for at least one day.";
            }
        }
        foreach (['valid_from', 'valid_to'] as $k) {
            if ($row[$k] !== null && !parse_date($row[$k])) {
                $errors[] = "Dose $n: invalid date.";
            }
        }
        if ($row['valid_from'] && $row['valid_to'] && $row['valid_to'] < $row['valid_from']) {
            $errors[] = "Dose $n: the 'until' date is before the 'from' date.";
        }
        $rows[] = $row;
    }
    if (!$form['as_needed'] && !$rows) {
        $errors[] = 'Add at least one dose time, or tick "Take as needed".';
    }

    if (!$errors) {
        $nullable = fn (string $v) => $v === '' ? null : $v;
        $values = [
            $form['name'], $nullable($form['dmd_code']), $nullable($form['strength']), $form['form'], $form['dose_unit'], $form['colour'],
            $nullable($form['reason']), $nullable($form['instructions']), $nullable($form['prescriber']), $nullable($form['notes']),
            $form['as_needed'], $nullable($form['prn_dose_amount']), $nullable($form['prn_max_daily']),
            $nullable($form['stock_count']), (int) $form['low_stock_days'], $form['start_date'], $nullable($form['end_date']),
        ];

        $oldSchedules = $med ? current_schedules((int) $med['id'], $todayYmd) : [];
        $medId = transaction(function () use ($med, $values, $rows, $uid, $form, $today, $todayYmd) {
            if ($med) {
                q('UPDATE medications SET name = ?, dmd_code = ?, strength = ?, form = ?, dose_unit = ?, colour = ?, reason = ?, instructions = ?,
                     prescriber = ?, notes = ?, as_needed = ?, prn_dose_amount = ?, prn_max_daily = ?, stock_count = ?,
                     low_stock_days = ?, start_date = ?, end_date = ? WHERE id = ? AND user_id = ?',
                    [...$values, $med['id'], $uid]);
                $medId = (int) $med['id'];
            } else {
                q('INSERT INTO medications (name, dmd_code, strength, form, dose_unit, colour, reason, instructions, prescriber, notes,
                     as_needed, prn_dose_amount, prn_max_daily, stock_count, low_stock_days, start_date, end_date, user_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $uid]);
                $medId = (int) db()->lastInsertId();
            }

            // As-needed medicines ignore schedules; leave any existing ones untouched so
            // switching back restores them.
            if ($form['as_needed']) {
                return $medId;
            }

            $current = array_column(current_schedules($medId, $todayYmd), null, 'id');
            $existing = array_map('intval', array_keys($current));
            $kept = [];
            foreach ($rows as $r) {
                // Reuse this row's existing schedule ids where possible so dose history
                // stays attached; prefer the one that already has the same amount.
                $pool = array_values(array_intersect($r['ids'], $existing));
                foreach (editor_row_doses($r) as [$amount, $mask]) {
                    $params = [$r['dose_time'] . ':00', $amount, $mask, $r['valid_from'], $r['valid_to']];
                    $match = null;
                    foreach ($pool as $i => $sid) {
                        if ((float) $current[$sid]['dose_amount'] === $amount) {
                            $match = $i;
                            break;
                        }
                    }
                    $match ??= $pool ? array_key_first($pool) : null;
                    if ($match !== null) {
                        $sid = $pool[$match];
                        unset($pool[$match]);
                        q('UPDATE medication_schedules SET dose_time = ?, dose_amount = ?, days_mask = ?, valid_from = ?, valid_to = ? WHERE id = ?',
                            [...$params, $sid]);
                        $kept[] = $sid;
                    } else {
                        q('INSERT INTO medication_schedules (dose_time, dose_amount, days_mask, valid_from, valid_to, medication_id) VALUES (?, ?, ?, ?, ?, ?)',
                            [...$params, $medId]);
                    }
                }
            }
            // Removed rows: retire them if doses were logged against them (so history and
            // adherence stay correct), otherwise delete outright.
            $yesterday = $today->modify('-1 day')->format('Y-m-d');
            foreach (array_diff($existing, $kept) as $sid) {
                $used = q('SELECT 1 FROM dose_logs WHERE schedule_id = ? LIMIT 1', [$sid])->fetch();
                if ($used) {
                    q('UPDATE medication_schedules SET valid_to = ? WHERE id = ?', [$yesterday, $sid]);
                } else {
                    q('DELETE FROM medication_schedules WHERE id = ?', [$sid]);
                }
            }
            return $medId;
        });

        // Dose change history.
        $saved = find_medication($medId, $uid);
        $newSchedules = current_schedules($medId, $todayYmd);
        $changeReason = mb_substr(post('change_reason'), 0, 255);
        $changeDate = parse_date(post('change_date')) ? post('change_date') : $todayYmd;
        $changes = [];
        if (!$med) {
            record_med_change($uid, $medId, $saved['start_date'], 'started',
                'Started ' . trim(($saved['strength'] ?? '') . ' ' . medication_regimen($saved, $newSchedules)), $changeReason);
        } else {
            $changes = describe_med_changes($med, $oldSchedules, $saved, $newSchedules);
            foreach ($changes as [$kind, $summary]) {
                record_med_change($uid, $medId, $changeDate, $kind, $summary, $changeReason);
            }
        }

        flash('success', $form['name'] . ($med ? ' updated.' : ' added.') . ($changes ? ' Change recorded: ' . lcfirst($changes[0][1]) . '.' : ''));
        redirect('medications.php');
    }
}

$pastRows = $med ? past_schedules((int) $med['id'], $todayYmd) : [];

function render_schedule_row(string $index, array $row): void
{
    $name = fn (string $field) => 'schedules[' . h($index) . '][' . $field . ']';
    $mode = $row['mode'] ?? 'same';
    $mask = (int) ($row['days_mask'] ?? EVERY_DAY);
    ?>
    <fieldset class="schedule-row">
        <legend class="sr">Dose time</legend>
        <input type="hidden" name="<?= $name('ids') ?>" value="<?= h(implode(',', $row['ids'] ?? [])) ?>">
        <div class="row-fields">
            <label>Time <input type="time" name="<?= $name('time') ?>" value="<?= h(substr((string) ($row['dose_time'] ?? ''), 0, 5)) ?>"></label>
            <button type="button" class="btn btn-small btn-danger remove-row" aria-label="Remove this dose time">Remove</button>
        </div>

        <div class="mode-switch" role="radiogroup" aria-label="How much to take">
            <label><input type="radio" name="<?= $name('mode') ?>" value="same"<?= checked($mode === 'same') ?>><span>Same amount</span></label>
            <label><input type="radio" name="<?= $name('mode') ?>" value="daily"<?= checked($mode === 'daily') ?>><span>Different amount each day</span></label>
        </div>

        <div class="mode-same">
            <label class="amount-field">Amount <input type="number" min="0.01" step="any" inputmode="decimal" name="<?= $name('amount') ?>" value="<?= h(num($row['dose_amount'] ?? '')) ?>"></label>
            <div class="days" role="group" aria-label="Days">
                <?php foreach (WEEKDAYS as $bit => $label): ?>
                <label class="day"><input type="checkbox" name="<?= $name('days') ?>[]" value="<?= $bit ?>"<?= checked(($mask & $bit) !== 0) ?>><span><?= $label ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mode-daily">
            <div class="day-amounts">
                <?php foreach (WEEKDAYS as $bit => $label): ?>
                <label><span><?= $label ?></span>
                    <input type="number" min="0" step="any" inputmode="decimal" name="<?= $name('day_amount') ?>[<?= $bit ?>]" value="<?= h($row['day_amounts'][$bit] ?? '') ?>" aria-label="Amount on <?= $label ?>">
                </label>
                <?php endforeach; ?>
            </div>
            <p class="muted small">Leave a day empty if there's no dose at this time on that day.</p>
        </div>

        <details class="row-dates"<?= !empty($row['valid_from']) || !empty($row['valid_to']) ? ' open' : '' ?>>
            <summary>Only between certain dates (e.g. a reducing dose)</summary>
            <div class="row-fields">
                <label>From <input type="date" name="<?= $name('valid_from') ?>" value="<?= h($row['valid_from'] ?? '') ?>"></label>
                <label>Until <input type="date" name="<?= $name('valid_to') ?>" value="<?= h($row['valid_to'] ?? '') ?>"></label>
            </div>
        </details>
    </fieldset>
    <?php
}

render_header($med ? 'Edit ' . $med['name'] : 'Add medication', 'meds');
?>
<div class="page-head">
    <h1><?= $med ? 'Edit ' . h($med['name']) : 'Add medication' ?></h1>
    <a class="btn" href="<?= h(url('medications.php')) ?>">Cancel</a>
</div>

<?= form_errors($errors) ?>

<form method="post" class="med-form">
    <?= csrf_field() ?>
    <section class="card">
        <h2>Medication</h2>
        <?php if (dmd_enabled()): ?>
        <div class="dmd-search" data-endpoint="<?= h(url('dmd_search.php')) ?>">
            <label for="dmd-q"><span>Find it in the NHS medicines dictionary <span class="muted small">(dm+d)</span></span></label>
            <input id="dmd-q" type="search" autocomplete="off" placeholder="Start typing, e.g. metformin 500"
                role="combobox" aria-autocomplete="list" aria-controls="dmd-results" aria-expanded="false">
            <ul id="dmd-results" class="dmd-results" role="listbox" aria-label="Matching medicines" hidden></ul>
            <p id="dmd-status" class="muted small" aria-live="polite">Pick a match to fill in the name, strength and form, or type them yourself below.</p>
        </div>
        <?php endif; ?>
        <input type="hidden" name="dmd_code" id="dmd_code" value="<?= h($form['dmd_code'] ?? '') ?>">
        <?php if (!empty($form['dmd_code'])): ?>
        <p class="small muted" id="dmd-linked">Linked to NHS dm+d code <?= h($form['dmd_code']) ?>.</p>
        <?php endif; ?>
        <div class="grid-2">
            <label>Name <input name="name" id="med-name" required maxlength="150" value="<?= h($form['name'] ?? '') ?>" placeholder="e.g. Metformin"></label>
            <label>Strength <input name="strength" id="med-strength" maxlength="60" value="<?= h($form['strength'] ?? '') ?>" placeholder="e.g. 500 mg"></label>
            <label>Form
                <select name="form" id="med-form">
                    <?php foreach (MED_FORMS as $f): ?><option value="<?= $f ?>"<?= selected($form['form'] === $f) ?>><?= ucfirst($f) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Dose measured in
                <select name="dose_unit" id="med-unit">
                    <?php foreach (DOSE_UNITS as $u): ?><option value="<?= $u ?>"<?= selected($form['dose_unit'] === $u) ?>><?= $u ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>What it's for <input name="reason" maxlength="150" value="<?= h($form['reason'] ?? '') ?>" placeholder="e.g. Type 2 diabetes"></label>
            <label>Prescribed by <input name="prescriber" maxlength="150" value="<?= h($form['prescriber'] ?? '') ?>" placeholder="e.g. Dr Patel"></label>
        </div>
        <label>Instructions <input name="instructions" maxlength="255" value="<?= h($form['instructions'] ?? '') ?>" placeholder="e.g. Take with food"></label>
        <fieldset class="colours">
            <legend>Colour</legend>
            <?php foreach (MED_COLOURS as $c): ?>
            <label class="colour-choice"><input type="radio" name="colour" value="<?= $c ?>"<?= checked($form['colour'] === $c) ?>><span class="dot dot-<?= $c ?>"></span><span class="sr"><?= $c ?></span></label>
            <?php endforeach; ?>
        </fieldset>
    </section>

    <section class="card">
        <h2>When to take it</h2>
        <label class="check"><input type="checkbox" name="as_needed" id="as_needed" value="1"<?= checked((bool) $form['as_needed']) ?>> Take as needed (no fixed times, e.g. painkillers or a reliever inhaler)</label>

        <div class="prn-fields" data-show-when="as_needed">
            <div class="grid-2">
                <label>Usual dose <input type="number" min="0" step="any" name="prn_dose_amount" value="<?= h(num($form['prn_dose_amount'] ?? '')) ?>"></label>
                <label>Maximum per day <input type="number" min="0" step="any" name="prn_max_daily" value="<?= h(num($form['prn_max_daily'] ?? '')) ?>"></label>
            </div>
        </div>

        <div class="schedule-fields" data-hide-when="as_needed">
            <p class="muted small">Add a row for each time of day, e.g. 2 in the morning and 1 at night.
                If the amount depends on the day of the week (e.g. 5 mg Mon/Wed/Fri, 3 mg other days), choose
                <strong>Different amount each day</strong>. Use the dates option for a reducing course.</p>
            <div id="schedule-rows" data-next-index="<?= count($rows) ?>">
                <?php foreach (array_values($rows) as $i => $row) { render_schedule_row((string) $i, $row); } ?>
            </div>
            <button type="button" class="btn" id="add-row">+ Add another dose time</button>
            <template id="schedule-template"><?php render_schedule_row('__i__', ['mode' => 'same', 'days_mask' => EVERY_DAY]); ?></template>

            <?php if ($pastRows): ?>
            <details class="past">
                <summary>Previous dose schedules (<?= count($pastRows) ?>)</summary>
                <ul><?php foreach ($pastRows as $p): ?><li class="muted small"><?= h(schedule_summary($p, $form['dose_unit'])) ?></li><?php endforeach; ?></ul>
            </details>
            <?php endif; ?>
        </div>

        <div class="grid-2">
            <label>Start date <input type="date" name="start_date" required value="<?= h($form['start_date'] ?? $todayYmd) ?>"></label>
            <label><span>End date <span class="muted small">(optional, for a set course)</span></span> <input type="date" name="end_date" value="<?= h($form['end_date'] ?? '') ?>"></label>
        </div>
    </section>

    <section class="card">
        <h2>Stock &amp; notes</h2>
        <div class="grid-2">
            <label><span>Stock on hand <span class="muted small">(optional; goes down as you log doses)</span></span>
                <input type="number" min="0" step="any" name="stock_count" value="<?= h(num($form['stock_count'] ?? '')) ?>"></label>
            <label>Warn me when this many days are left
                <input type="number" min="0" max="365" name="low_stock_days" value="<?= (int) ($form['low_stock_days'] ?? 7) ?>"></label>
        </div>
        <label>Notes <textarea name="notes" rows="3" maxlength="5000"><?= h($form['notes'] ?? '') ?></textarea></label>
    </section>

    <section class="card">
        <h2><?= $med ? 'Changing the dose?' : 'Notes for your history' ?></h2>
        <p class="muted small"><?= $med
            ? 'If you change the strength, amount or timing, Pillbox records it in this medicine\'s history (e.g. "Increased from 10mg to 20mg a day"). Add when and why if you like.'
            : 'Optional: who prescribed it and why, for this medicine\'s history.' ?></p>
        <div class="grid-2">
            <?php if ($med): ?>
            <label>Change takes effect from <input type="date" name="change_date" value="<?= h(post('change_date') ?: $todayYmd) ?>"></label>
            <?php endif; ?>
            <label><span>Reason or who changed it <span class="muted small">(optional)</span></span>
                <input name="change_reason" maxlength="255" value="<?= h(post('change_reason')) ?>" placeholder="e.g. Dr Patel, after blood pressure review"></label>
        </div>
    </section>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $med ? 'Save changes' : 'Add medication' ?></button>
        <a class="btn" href="<?= h(url('medications.php')) ?>">Cancel</a>
    </div>
</form>

<?php if ($med): ?>
<form method="post" action="<?= h(url('medications.php')) ?>" class="danger-zone" data-confirm="Permanently delete <?= h($med['name']) ?> and ALL of its dose history? This cannot be undone. (Use Stop instead to keep the history.)">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $med['id'] ?>">
    <button class="btn btn-danger" name="action" value="delete">Delete medication permanently</button>
</form>
<?php endif; ?>
<?php render_footer();
