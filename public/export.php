<?php
/*
 * CSV export of dose logs or health readings for a date range.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$today = local_today();
$to = parse_date(get('to')) ?? $today;
$from = parse_date(get('from')) ?? $to->modify('-29 days');
$utc = new DateTimeZone('UTC');
$fromUtc = $from->setTimezone($utc)->format('Y-m-d H:i:s');
$toUtc = $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$what = get('what') === 'readings' ? 'readings' : 'doses';
// Someone with shared access needs that section too, not just "Reports and exports".
if (!share_can($what === 'readings' ? 'readings' : 'doses', 'view')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit(($what === 'readings' ? 'Readings' : 'Medicines') . " haven't been shared with you, so they can't be downloaded.\n");
}

/** Stop spreadsheet apps from treating user text as a formula. */
function csv_safe(mixed $v): string
{
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) ? "'" . $v : $v;
}

header('Content-Type: text/csv; charset=utf-8');
header(sprintf('Content-Disposition: attachment; filename="pillbox-%s-%s-to-%s.csv"', $what, $from->format('Y-m-d'), $to->format('Y-m-d')));
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows symbols like ° correctly

if ($what === 'doses') {
    fputcsv($out, ['Date', 'Scheduled time', 'Recorded time', 'Medication', 'Strength', 'Status', 'Amount', 'Unit', 'Type', 'Notes'], ',', '"', '\\');
    foreach (dose_slots($user, $from, $to) as $slot) {
        $s = $slot['schedule'];
        fputcsv($out, array_map('csv_safe', [
            $slot['local']->format('Y-m-d'), $slot['local']->format('H:i'),
            $slot['log'] ? local_time($slot['log']['taken_at'], 'Y-m-d H:i') : '',
            $s['name'], $s['strength'], $slot['status'],
            $slot['log'] ? num($slot['log']['amount']) : num($s['dose_amount']), $s['dose_unit'], 'scheduled',
            $slot['log']['notes'] ?? '',
        ]), ',', '"', '\\');
    }
    foreach (array_reverse(prn_logs($uid, $fromUtc, $toUtc)) as $log) {
        fputcsv($out, array_map('csv_safe', [
            local_time($log['taken_at'], 'Y-m-d'), '', local_time($log['taken_at'], 'Y-m-d H:i'),
            $log['name'], $log['strength'], 'taken', num($log['amount']), $log['dose_unit'], 'as needed', $log['notes'],
        ]), ',', '"', '\\');
    }
} else {
    $defs = user_metric_definitions($uid);
    fputcsv($out, ['Date & time', 'Measurement', 'Reading', 'Value 1', 'Value 2', 'Value 3', 'Context', 'Notes'], ',', '"', '\\');
    foreach (readings_q('SELECT * FROM health_readings WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at', [$uid, $fromUtc, $toUtc]) as $r) {
        $def = $defs[$r['metric']] ?? null;
        fputcsv($out, array_map('csv_safe', [
            local_time($r['recorded_at'], 'Y-m-d H:i'), $def['label'] ?? $r['metric'], $def ? format_reading($def, $r) : '',
            num($r['value1']), num($r['value2']), num($r['value3']), $r['context'], $r['notes'],
        ]), ',', '"', '\\');
    }
}
fclose($out);
