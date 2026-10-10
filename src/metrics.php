<?php
declare(strict_types=1);

/*
 * Health measurements. Each metric stores up to three numbers (value1..value3)
 * plus an optional context tag and notes. Users can switch metrics on or off,
 * and add their own (e.g. something a doctor has asked them to monitor).
 */

const DEFAULT_METRICS = ['blood_pressure', 'heart_rate', 'blood_glucose', 'weight', 'temperature', 'oxygen_saturation', 'pain', 'symptom'];

function builtin_metrics(): array
{
    $f = static fn (string $label, string $unit, float $min, float $max, float $step = 1, bool $required = true): array =>
        compact('label', 'unit', 'min', 'max', 'step', 'required');

    return [
        'blood_pressure' => [
            'label' => 'Blood pressure',
            'fields' => [$f('Systolic', 'mmHg', 50, 260), $f('Diastolic', 'mmHg', 30, 160), $f('Pulse', 'bpm', 20, 250, 1, false)],
            'contexts' => ['Sitting', 'Standing', 'Lying down', 'After exercise', 'Left arm', 'Right arm'],
            'help' => 'Rest for 5 minutes before measuring. Record each reading if your doctor asked for more than one.',
        ],
        'heart_rate' => ['label' => 'Heart rate', 'fields' => [$f('Heart rate', 'bpm', 20, 250)], 'contexts' => ['Resting', 'After exercise', 'On waking']],
        'blood_glucose' => [
            'label' => 'Blood glucose',
            'fields' => [$f('Glucose', 'mmol/L', 0.5, 40, 0.1)],
            'contexts' => ['Fasting', 'Before meal', '2h after meal', 'Bedtime', 'Random', 'Hypo symptoms'],
        ],
        'blood_ketones' => ['label' => 'Blood ketones', 'fields' => [$f('Ketones', 'mmol/L', 0, 10, 0.1)]],
        'weight' => ['label' => 'Weight', 'fields' => [$f('Weight', 'kg', 1, 400, 0.1)]],
        'temperature' => ['label' => 'Temperature', 'fields' => [$f('Temperature', '°C', 30, 45, 0.1)], 'contexts' => ['Oral', 'Ear', 'Forehead', 'Armpit']],
        'oxygen_saturation' => ['label' => 'Oxygen saturation', 'fields' => [$f('SpO₂', '%', 50, 100)], 'contexts' => ['At rest', 'After exercise', 'On oxygen']],
        'respiratory_rate' => ['label' => 'Breathing rate', 'fields' => [$f('Breaths', '/min', 4, 60)]],
        'peak_flow' => ['label' => 'Peak flow', 'fields' => [$f('Peak flow', 'L/min', 50, 900, 10)], 'contexts' => ['Morning', 'Evening', 'Before inhaler', 'After inhaler']],
        'inr' => ['label' => 'INR', 'fields' => [$f('INR', '', 0.5, 10, 0.1)], 'help' => 'For people on warfarin or similar anticoagulants.'],
        'cholesterol' => ['label' => 'Cholesterol', 'fields' => [$f('Total', 'mmol/L', 1, 20, 0.1), $f('HDL', 'mmol/L', 0.1, 5, 0.1, false), $f('LDL', 'mmol/L', 0.1, 15, 0.1, false)]],
        'pain' => ['label' => 'Pain score', 'fields' => [$f('Pain', '/10', 0, 10)], 'contexts' => ['Head', 'Back', 'Joints', 'Chest', 'Abdomen', 'Other']],
        'mood' => ['label' => 'Mood', 'fields' => [$f('Mood', '/10', 0, 10)]],
        'sleep' => ['label' => 'Sleep', 'fields' => [$f('Sleep', 'hours', 0, 24, 0.25)], 'contexts' => SLEEP_QUALITY],
        'fluid_intake' => ['label' => 'Fluid intake', 'fields' => [$f('Fluid', 'ml', 0, 10000, 50)]],
        'steps' => ['label' => 'Steps', 'fields' => [$f('Steps', 'steps', 0, 100000)]],
        'hrv' => ['label' => 'Heart rate variability', 'fields' => [$f('HRV', 'ms', 1, 300)], 'help' => 'From a watch, ring or strap: usually measured overnight.'],
        'calories' => ['label' => 'Calories eaten', 'fields' => [$f('Calories', 'kcal', 0, 15000, 10)]],
        'symptom' => [
            'label' => 'Symptom / side effect',
            'fields' => [$f('Severity', '/10', 0, 10)],
            'notes_required' => true,
            'help' => 'Describe the symptom in the notes, e.g. "dizzy after morning tablets".',
        ],
    ];
}

/** All metrics available to a user: built-ins plus their custom ones (keyed 'c<id>'). */
function user_metric_definitions(int $userId): array
{
    $defs = builtin_metrics();
    foreach (q('SELECT * FROM custom_metrics WHERE user_id = ? ORDER BY label', [$userId]) as $c) {
        $defs['c' . $c['id']] = [
            'label' => $c['label'],
            'fields' => [['label' => $c['label'], 'unit' => (string) $c['unit'], 'min' => -1000000, 'max' => 1000000, 'step' => 0.01, 'required' => true]],
            'custom' => true,
        ];
    }
    return $defs;
}

/** Metric definitions the user has switched on, in display order. Custom metrics are always on. */
function enabled_metrics(array $user): array
{
    $all = user_metric_definitions((int) $user['id']);
    $keys = $user['enabled_metrics'] !== null ? array_filter(explode(',', $user['enabled_metrics'])) : DEFAULT_METRICS;
    $enabled = [];
    foreach ($keys as $key) {
        if (isset($all[$key])) {
            $enabled[$key] = $all[$key];
        }
    }
    foreach ($all as $key => $def) {
        if (!empty($def['custom'])) {
            $enabled[$key] = $def;
        }
    }
    return $enabled;
}

/** "128/82 mmHg, pulse 70 bpm" style display of a reading row. */
function format_reading(array $def, array $row): string
{
    $values = [];
    foreach ($def['fields'] as $i => $field) {
        $v = $row['value' . ($i + 1)];
        if ($v !== null) {
            $values[$i] = num($v);
        }
    }
    if (!$values) {
        return '';
    }
    $fields = $def['fields'];
    // Blood pressure reads naturally as "sys/dia".
    if (count($fields) >= 2 && $fields[0]['unit'] === 'mmHg' && $fields[1]['unit'] === 'mmHg' && isset($values[0], $values[1])) {
        $text = $values[0] . '/' . $values[1] . ' mmHg';
        if (isset($values[2])) {
            $text .= ', pulse ' . $values[2] . ' ' . $fields[2]['unit'];
        }
        return $text;
    }
    $parts = [];
    foreach ($values as $i => $v) {
        $unit = $fields[$i]['unit'];
        $prefix = count($fields) > 1 ? $fields[$i]['label'] . ' ' : '';
        $parts[] = trim($prefix . $v . ($unit !== '' && $unit[0] !== '/' && $unit !== '%' && $unit !== '°C' ? ' ' : '') . $unit);
    }
    return implode(', ', $parts);
}

/**
 * Validate submitted reading values. Returns [values(3), errors].
 */
function validate_reading(array $def, array $input): array
{
    $values = [null, null, null];
    $errors = [];
    foreach ($def['fields'] as $i => $field) {
        $raw = trim((string) ($input['value' . ($i + 1)] ?? ''));
        if ($raw === '') {
            if ($field['required']) {
                $errors[] = $field['label'] . ' is required.';
            }
            continue;
        }
        if (!is_numeric($raw)) {
            $errors[] = $field['label'] . ' must be a number.';
            continue;
        }
        $v = (float) $raw;
        if ($v < $field['min'] || $v > $field['max']) {
            $errors[] = sprintf('%s must be between %s and %s.', $field['label'], num($field['min']), num($field['max']));
            continue;
        }
        $values[$i] = $v;
    }
    return [$values, $errors];
}

function metric_stats(array $def, array $rows): array
{
    $stats = [];
    foreach ($def['fields'] as $i => $field) {
        $vals = array_values(array_filter(array_map(fn ($r) => $r['value' . ($i + 1)], $rows), fn ($v) => $v !== null));
        if (!$vals) {
            continue;
        }
        $vals = array_map('floatval', $vals);
        $stats[] = [
            'label' => $field['label'],
            'unit'  => $field['unit'],
            'count' => count($vals),
            'avg'   => array_sum($vals) / count($vals),
            'min'   => min($vals),
            'max'   => max($vals),
        ];
    }
    return $stats;
}
