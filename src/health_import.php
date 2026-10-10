<?php
declare(strict_types=1);

/*
 * Importing Apple Health data.
 *
 * Apple doesn't let websites read Health directly, so data arrives either as the
 * "Export All Health Data" file (export.zip / export.xml) or as JSON posted by an
 * iPhone automation (Shortcuts, or the Health Auto Export app) to a private sync URL.
 *
 * Only measurements Pillbox tracks are taken. Spot readings (blood pressure, weight,
 * glucose, ...) are kept individually; busy streams (oxygen, breathing rate, resting
 * heart rate, steps, sleep) become one value per day. Every imported reading has an
 * external_id, so importing the same data again never creates duplicates.
 */

const HEALTH_SOURCE = 'apple_health';

/** Apple Health type => [Pillbox metric, how to import, context]. */
const HEALTH_TYPES = [
    'HKQuantityTypeIdentifierBloodPressureSystolic'  => ['blood_pressure', 'bp_sys', null],
    'HKQuantityTypeIdentifierBloodPressureDiastolic' => ['blood_pressure', 'bp_dia', null],
    'HKQuantityTypeIdentifierBodyMass'               => ['weight', 'each', null],
    'HKQuantityTypeIdentifierBloodGlucose'           => ['blood_glucose', 'each', null],
    'HKQuantityTypeIdentifierBodyTemperature'        => ['temperature', 'each', null],
    'HKQuantityTypeIdentifierPeakExpiratoryFlowRate' => ['peak_flow', 'each', null],
    'HKQuantityTypeIdentifierRestingHeartRate'       => ['heart_rate', 'daily_avg', 'Resting'],
    // Raw heart rate (a watch records thousands a day) is only taken from automations, as a daily average.
    'HKQuantityTypeIdentifierHeartRate'              => ['heart_rate', 'daily_avg', null],
    'HKQuantityTypeIdentifierOxygenSaturation'       => ['oxygen_saturation', 'daily_avg', null],
    'HKQuantityTypeIdentifierRespiratoryRate'        => ['respiratory_rate', 'daily_avg', null],
    'HKQuantityTypeIdentifierStepCount'              => ['steps', 'daily_sum', null],
    'HKCategoryTypeIdentifierSleepAnalysis'          => ['sleep', 'sleep', null],
];

/** Other names for the same types: Shortcuts' display names, Health Auto Export's names, Pillbox's own. */
const HEALTH_TYPE_ALIASES = [
    'blood_pressure_systolic' => 'HKQuantityTypeIdentifierBloodPressureSystolic',
    'blood_pressure_diastolic' => 'HKQuantityTypeIdentifierBloodPressureDiastolic',
    'weight' => 'HKQuantityTypeIdentifierBodyMass', 'body_mass' => 'HKQuantityTypeIdentifierBodyMass',
    'weight_body_mass' => 'HKQuantityTypeIdentifierBodyMass',
    'blood_glucose' => 'HKQuantityTypeIdentifierBloodGlucose',
    'body_temperature' => 'HKQuantityTypeIdentifierBodyTemperature', 'temperature' => 'HKQuantityTypeIdentifierBodyTemperature',
    'peak_expiratory_flow_rate' => 'HKQuantityTypeIdentifierPeakExpiratoryFlowRate', 'peak_flow' => 'HKQuantityTypeIdentifierPeakExpiratoryFlowRate',
    'resting_heart_rate' => 'HKQuantityTypeIdentifierRestingHeartRate', 'heart_rate' => 'HKQuantityTypeIdentifierHeartRate',
    'oxygen_saturation' => 'HKQuantityTypeIdentifierOxygenSaturation', 'blood_oxygen_saturation' => 'HKQuantityTypeIdentifierOxygenSaturation',
    'respiratory_rate' => 'HKQuantityTypeIdentifierRespiratoryRate',
    'steps' => 'HKQuantityTypeIdentifierStepCount', 'step_count' => 'HKQuantityTypeIdentifierStepCount',
    'sleep' => 'HKCategoryTypeIdentifierSleepAnalysis', 'sleep_analysis' => 'HKCategoryTypeIdentifierSleepAnalysis',
];

function health_type(string $name): ?string
{
    if (isset(HEALTH_TYPES[$name])) {
        return $name;
    }
    $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    return HEALTH_TYPE_ALIASES[$key] ?? null;
}

/** Convert a value to the units Pillbox uses for a metric; null if it can't be. */
function health_convert(string $metric, float $value, string $unit): ?float
{
    $u = strtolower(str_replace(' ', '', $unit));
    switch ($metric) {
        case 'weight':
            $value *= match (true) { str_starts_with($u, 'lb') => 0.45359237, $u === 'st' => 6.35029318, $u === 'g' => 0.001, default => 1.0 };
            break;
        case 'blood_glucose':
            if (str_contains($u, 'mg')) {
                $value /= 18.0156; // mg/dL -> mmol/L
            }
            break;
        case 'temperature':
            if (str_contains($u, 'f')) {
                $value = ($value - 32) * 5 / 9;
            }
            break;
        case 'oxygen_saturation':
            if ($value <= 1.0) {
                $value *= 100; // Apple stores SpO2 as a fraction
            }
            break;
        case 'peak_flow':
            if (str_starts_with($u, 'ml')) {
                $value /= 1000;
            }
            break;
        case 'blood_pressure':
            if ($u === 'kpa') {
                $value *= 7.50062;
            }
            break;
    }
    return round($value, 2);
}

final class HealthImporter
{
    /** Rows ready to save. */
    private array $rows = [];
    /** [metric][date][source] => [sum, count] for daily values. */
    private array $daily = [];
    /** Systolic/diastolic halves waiting to be paired: key => ['sys' => .., 'dia' => .., 'at' => ..]. */
    private array $bp = [];
    public int $samples = 0;
    public int $tooOld = 0;
    public int $invalid = 0;

    public function __construct(private DateTimeZone $tz, private ?DateTimeImmutable $since = null)
    {
    }

    /** One sample from any source. $value is a number, or a sleep stage name for sleep. */
    public function add(string $typeName, string $start, string $end, string|float $value, string $unit, string $source): void
    {
        $type = health_type($typeName);
        if ($type === null) {
            return;
        }
        [$metric, $mode, $context] = HEALTH_TYPES[$type];
        try {
            $startAt = new DateTimeImmutable($start);
            $endAt = new DateTimeImmutable($end !== '' ? $end : $start);
        } catch (Exception) {
            $this->invalid++;
            return;
        }
        $this->samples++;
        if ($this->since !== null && $endAt < $this->since) {
            $this->tooOld++;
            return;
        }

        if ($mode === 'sleep') {
            // Apple sleep stages: count time asleep (core, deep, REM, unspecified), not "in bed" or "awake".
            if (is_string($value) && !str_contains($value, 'Asleep')) {
                return;
            }
            $hours = is_numeric($value) ? (float) $value : ($endAt->getTimestamp() - $startAt->getTimestamp()) / 3600;
            $this->addDaily($metric, $endAt, $source, $hours, 'sleep');
            return;
        }
        if (!is_numeric($value)) {
            $this->invalid++;
            return;
        }
        $v = health_convert($metric, (float) $value, $unit);

        switch ($mode) {
            case 'bp_sys':
            case 'bp_dia':
                $key = $startAt->getTimestamp() . '|' . $source;
                $this->bp[$key][$mode === 'bp_sys' ? 'sys' : 'dia'] = $v;
                $this->bp[$key]['at'] = $startAt;
                $this->bp[$key]['source'] = $source;
                break;
            case 'daily_sum':
            case 'daily_avg':
                $this->addDaily($metric, $startAt, $source, $v, $mode, $context);
                break;
            default:
                $this->addRow($metric, [$v, null], $startAt, $context, $source, false);
        }
    }

    /** A complete blood pressure reading (from JSON sources). */
    public function addBloodPressure(float $systolic, float $diastolic, string $date, string $source, ?float $pulse = null): void
    {
        try {
            $at = new DateTimeImmutable($date);
        } catch (Exception) {
            $this->invalid++;
            return;
        }
        $this->samples++;
        if ($this->since !== null && $at < $this->since) {
            $this->tooOld++;
            return;
        }
        $this->addRow('blood_pressure', [$systolic, $diastolic, $pulse], $at, null, $source, false);
    }

    private function addDaily(string $metric, DateTimeImmutable $at, string $source, float $v, string $mode, ?string $context = null): void
    {
        $date = $at->setTimezone($this->tz)->format('Y-m-d');
        $cell = &$this->daily[$metric . '|' . $context][$date][$source];
        $cell ??= ['sum' => 0.0, 'n' => 0, 'mode' => $mode, 'context' => $context];
        $cell['sum'] += $v;
        $cell['n']++;
    }

    private function addRow(string $metric, array $values, DateTimeImmutable $at, ?string $context, string $source, bool $daily, ?string $key = null): void
    {
        $def = builtin_metrics()[$metric];
        foreach ($def['fields'] as $i => $field) {
            $v = $values[$i] ?? null;
            if ($v === null) {
                if ($field['required']) {
                    $this->invalid++;
                    return;
                }
                continue;
            }
            if ($v < $field['min'] || $v > $field['max']) {
                $this->invalid++;
                return;
            }
        }
        $utc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->rows[] = [
            'metric' => $metric, 'values' => array_pad($values, 3, null), 'recorded_at' => $utc, 'context' => $context,
            // Daily values are identified by their date (so a later import can update a partial day);
            // individual samples by their time and source.
            'external_id' => sha1(HEALTH_SOURCE . '|' . $metric . '|' . ($key ?? $utc . '|' . $source)),
            'replace' => $daily,
        ];
    }

    /** Finish pairing and daily totals, and return the rows to save. */
    public function rows(): array
    {
        foreach ($this->bp as $half) {
            if (isset($half['sys'], $half['dia'])) {
                $this->addRow('blood_pressure', [$half['sys'], $half['dia']], $half['at'], null, $half['source'], false);
            }
        }
        $this->bp = [];
        foreach ($this->daily as $metricKey => $days) {
            $metric = explode('|', $metricKey)[0];
            foreach ($days as $date => $sources) {
                $values = [];
                foreach ($sources as $cell) {
                    $values[] = $cell['mode'] === 'daily_avg' ? $cell['sum'] / $cell['n'] : $cell['sum'];
                }
                $first = reset($sources);
                // Sums (steps, sleep) from several devices overlap, so take the biggest single
                // device rather than adding them up. Averages can be averaged across devices.
                $value = $first['mode'] === 'daily_avg' ? array_sum($values) / count($values) : max($values);
                $noon = new DateTimeImmutable($date . ' 12:00', $this->tz);
                $this->addRow($metric, [round($value, $metric === 'steps' ? 0 : 2)], $noon, $first['context'], '', true, $date . '|' . $first['context']);
            }
        }
        $this->daily = [];
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }
}

/**
 * Save imported rows for a user.
 * @return array{added: int, updated: int, unchanged: int, metrics: array<string, int>}
 */
function save_health_rows(int $userId, array $rows, string $source = HEALTH_SOURCE): array
{
    $result = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'metrics' => []];
    if (!$rows) {
        return $result;
    }
    $insert = 'INSERT INTO health_readings (user_id, metric, value1, value2, value3, context, recorded_at, source, external_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $ignore = db()->prepare($insert . ' ON DUPLICATE KEY UPDATE id = id');
    // Daily averages: the newest figure wins.
    $replace = db()->prepare($insert . ' ON DUPLICATE KEY UPDATE value1 = VALUES(value1), value2 = VALUES(value2), value3 = VALUES(value3)');
    // Daily totals (steps, sleep) only grow during a day, so a sync that sends just the
    // latest part of a day must not shrink the total.
    $grow = db()->prepare($insert . ' ON DUPLICATE KEY UPDATE value1 = GREATEST(value1, VALUES(value1))');
    transaction(function () use ($userId, $rows, $source, $ignore, $replace, $grow, &$result) {
        foreach ($rows as $r) {
            $stmt = !$r['replace'] ? $ignore : (in_array($r['metric'], ['steps', 'sleep'], true) ? $grow : $replace);
            $stmt->execute([$userId, $r['metric'], ...$r['values'], $r['context'], $r['recorded_at'], $source, $r['external_id']]);
            $n = $stmt->rowCount(); // 1 = inserted, 2 = updated, 0 = already there
            $result[$n === 1 ? 'added' : ($n === 2 ? 'updated' : 'unchanged')]++;
            if ($n > 0) {
                $result['metrics'][$r['metric']] = ($result['metrics'][$r['metric']] ?? 0) + 1;
            }
        }
    });
    health_enable_metrics($userId, array_keys($result['metrics']));
    return $result;
}

/** Switch on any measurement that just received data, so it shows up on the Readings page. */
function health_enable_metrics(int $userId, array $metrics): void
{
    if (!$metrics) {
        return;
    }
    $current = q('SELECT enabled_metrics FROM users WHERE id = ?', [$userId])->fetchColumn();
    $enabled = $current === null ? DEFAULT_METRICS : array_filter(explode(',', (string) $current));
    $merged = array_values(array_unique(array_merge($enabled, $metrics)));
    if ($merged !== array_values($enabled)) {
        q('UPDATE users SET enabled_metrics = ? WHERE id = ?', [implode(',', $merged), $userId]);
    }
}

/**
 * Read an Apple Health export (export.zip or export.xml) into the importer.
 * Streams the file, so even very large exports use little memory.
 * @throws RuntimeException
 */
function read_apple_health_export(string $path, HealthImporter $importer): void
{
    if (!class_exists(XMLReader::class)) {
        throw new RuntimeException("This server's PHP is missing the XMLReader extension, which is needed to read Apple Health exports.");
    }
    $uri = $path;
    $head = (string) file_get_contents($path, false, null, 0, 4);
    if ($head === "PK\x03\x04") {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("This server can't open .zip files. Unzip export.zip on your computer and upload the export.xml file inside it instead.");
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("That .zip file couldn't be opened. Try exporting from the Health app again.");
        }
        $entry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (basename($name) === 'export.xml') {
                $entry = $name;
                break;
            }
        }
        $zip->close();
        if ($entry === null) {
            throw new RuntimeException("That .zip doesn't contain export.xml. Make sure you uploaded the file from Health → Export All Health Data.");
        }
        $uri = 'zip://' . $path . '#' . $entry;
    }

    $reader = new XMLReader();
    if (!$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException("That file couldn't be read as an Apple Health export.");
    }
    $sawHealthData = false;
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        if ($reader->name === 'HealthData') {
            $sawHealthData = true;
        } elseif ($reader->name === 'Record') {
            $type = (string) $reader->getAttribute('type');
            // The export already has a daily resting heart rate; skip the raw watch samples.
            if (isset(HEALTH_TYPES[$type]) && $type !== 'HKQuantityTypeIdentifierHeartRate') {
                $importer->add($type, (string) $reader->getAttribute('startDate'), (string) $reader->getAttribute('endDate'),
                    (string) $reader->getAttribute('value'), (string) $reader->getAttribute('unit'), (string) $reader->getAttribute('sourceName'));
            }
        }
    }
    $reader->close();
    if (!$sawHealthData) {
        throw new RuntimeException("That file isn't an Apple Health export (no HealthData found).");
    }
}

/**
 * Read JSON posted by an iPhone automation. Accepts Pillbox's simple format
 *   {"samples": [{"type": "weight", "value": 72.5, "unit": "kg", "date": "..."}, ...]}
 * and the Health Auto Export app's format {"data": {"metrics": [...]}}.
 * @throws RuntimeException
 */
function read_health_json(array $json, HealthImporter $importer): void
{
    if (isset($json['samples']) && is_array($json['samples'])) {
        foreach ($json['samples'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $source = (string) ($s['source'] ?? 'Shortcuts');
            $date = (string) ($s['date'] ?? $s['start'] ?? $s['startDate'] ?? '');
            if (isset($s['systolic'], $s['diastolic'])) {
                $importer->addBloodPressure((float) $s['systolic'], (float) $s['diastolic'], $date, $source, isset($s['pulse']) ? (float) $s['pulse'] : null);
                continue;
            }
            $importer->add((string) ($s['type'] ?? ''), $date, (string) ($s['end'] ?? $s['endDate'] ?? $date),
                is_numeric($s['value'] ?? null) ? (float) $s['value'] : (string) ($s['value'] ?? ''), (string) ($s['unit'] ?? ''), $source);
        }
        return;
    }
    $metrics = $json['data']['metrics'] ?? null;
    if (!is_array($metrics)) {
        throw new RuntimeException('Unrecognised format: expected "samples" or Health Auto Export "data.metrics".');
    }
    foreach ($metrics as $m) {
        $name = (string) ($m['name'] ?? '');
        $unit = (string) ($m['units'] ?? '');
        foreach ((array) ($m['data'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $date = (string) ($p['date'] ?? '');
            $source = (string) ($p['source'] ?? 'Health Auto Export');
            if ($name === 'blood_pressure' && isset($p['systolic'], $p['diastolic'])) {
                $importer->addBloodPressure((float) $p['systolic'], (float) $p['diastolic'], $date, $source);
            } elseif ($name === 'sleep_analysis') {
                $hours = $p['asleep'] ?? $p['totalSleep'] ?? null;
                if (is_numeric($hours) && (float) $hours > 0) {
                    $end = (string) ($p['sleepEnd'] ?? $p['inBedEnd'] ?? $date);
                    $importer->add('sleep', $end, $end, (float) $hours, 'hr', $source);
                }
            } else {
                $value = $p['qty'] ?? $p['Avg'] ?? $p['avg'] ?? null;
                if (is_numeric($value)) {
                    $importer->add($name, $date, $date, (float) $value, $unit, $source);
                }
            }
        }
    }
}

/** Human summary of an import result. */
function health_import_summary(array $result, HealthImporter $importer): string
{
    $defs = builtin_metrics();
    $parts = [];
    foreach ($result['metrics'] as $metric => $n) {
        $parts[] = $n . ' ' . mb_strtolower($defs[$metric]['label']);
    }
    $text = $result['added'] || $result['updated']
        ? 'Imported from Apple Health: ' . implode(', ', $parts) . '.'
        : 'Nothing new to import from Apple Health.';
    if ($result['unchanged']) {
        $text .= ' ' . $result['unchanged'] . ' reading' . ($result['unchanged'] === 1 ? ' was' : 's were') . ' already in Pillbox.';
    }
    if ($importer->invalid) {
        $text .= ' ' . $importer->invalid . ' unusable value' . ($importer->invalid === 1 ? '' : 's') . ' skipped.';
    }
    return $text;
}
