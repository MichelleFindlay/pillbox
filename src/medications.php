<?php
declare(strict_types=1);

const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 4 => 'Wed', 8 => 'Thu', 16 => 'Fri', 32 => 'Sat', 64 => 'Sun'];
const EVERY_DAY = 127;

const MED_FORMS = ['tablet', 'capsule', 'liquid', 'inhaler', 'injection', 'patch', 'drops', 'cream', 'spray', 'suppository', 'sachet', 'other'];
const DOSE_UNITS = ['tablet', 'capsule', 'ml', 'mg', 'mcg', 'g', 'puff', 'drop', 'unit', 'patch', 'sachet', 'spray', 'application', 'injection', 'suppository', 'lozenge'];
// Medication colours, used as CSS classes (.dot-<colour>) so no inline styles are needed.
const MED_COLOURS = ['blue', 'red', 'green', 'orange', 'purple', 'pink', 'teal', 'yellow', 'grey'];

function weekday_bit(DateTimeInterface $date): int
{
    return 1 << ((int) $date->format('N') - 1);
}

function days_label(int $mask): string
{
    $mask &= EVERY_DAY;
    return match ($mask) {
        EVERY_DAY => 'Every day',
        31 => 'Weekdays',
        96 => 'Weekends',
        0 => 'Never',
        default => implode(', ', array_values(array_filter(WEEKDAYS, fn ($bit) => ($mask & $bit) !== 0, ARRAY_FILTER_USE_KEY))),
    };
}

function user_medications(int $userId, bool $activeOnly = true): array
{
    return q(
        'SELECT * FROM medications WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY is_active DESC, name',
        [$userId]
    )->fetchAll();
}

function find_medication(int $id, int $userId): ?array
{
    return q('SELECT * FROM medications WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
}

/** Schedules still in effect today or later (the ones shown in the editor). */
function current_schedules(int $medId, string $todayYmd): array
{
    return q(
        'SELECT * FROM medication_schedules
         WHERE medication_id = ? AND (valid_to IS NULL OR valid_to >= ?)
         ORDER BY valid_from IS NOT NULL, valid_from, dose_time',
        [$medId, $todayYmd]
    )->fetchAll();
}

function past_schedules(int $medId, string $todayYmd): array
{
    return q(
        'SELECT * FROM medication_schedules WHERE medication_id = ? AND valid_to < ? ORDER BY valid_to DESC, dose_time',
        [$medId, $todayYmd]
    )->fetchAll();
}

/** Schedules for many medications at once, grouped by medication id. */
function schedules_by_medication(array $medIds, string $todayYmd): array
{
    if (!$medIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($medIds), '?'));
    $rows = q(
        "SELECT * FROM medication_schedules
         WHERE medication_id IN ($in) AND (valid_to IS NULL OR valid_to >= ?)
         ORDER BY dose_time",
        [...$medIds, $todayYmd]
    )->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['medication_id']][] = $row;
    }
    return $grouped;
}

function schedule_summary(array $schedule, string $unit): string
{
    $text = substr($schedule['dose_time'], 0, 5) . ' · ' . dose_label($schedule['dose_amount'], $unit) . ' · ' . days_label((int) $schedule['days_mask']);
    if ($schedule['valid_from'] || $schedule['valid_to']) {
        $from = $schedule['valid_from'] ? date('j M', strtotime($schedule['valid_from'])) : 'now';
        $to = $schedule['valid_to'] ? date('j M Y', strtotime($schedule['valid_to'])) : 'ongoing';
        $text .= " · $from – $to";
    }
    return $text;
}

/**
 * Turn stored schedule rows into editor rows. Rows that share a time and date range
 * and cover different weekdays become one "different amount each day" row.
 *
 * Editor row: ids, dose_time, mode ('same'|'daily'), dose_amount, days_mask,
 * day_amounts [weekday bit => amount], valid_from, valid_to.
 */
function schedules_for_editing(array $schedules): array
{
    $groups = [];
    foreach ($schedules as $s) {
        $groups[substr($s['dose_time'], 0, 5) . '|' . $s['valid_from'] . '|' . $s['valid_to']][] = $s;
    }
    $rows = [];
    foreach ($groups as $group) {
        $covered = 0;
        $disjoint = true;
        foreach ($group as $s) {
            $disjoint = $disjoint && ($covered & (int) $s['days_mask']) === 0;
            $covered |= (int) $s['days_mask'];
        }
        if (count($group) > 1 && $disjoint) {
            $dayAmounts = [];
            foreach ($group as $s) {
                foreach (WEEKDAYS as $bit => $_) {
                    if ((int) $s['days_mask'] & $bit) {
                        $dayAmounts[$bit] = num($s['dose_amount']);
                    }
                }
            }
            $rows[] = editor_row($group[0], array_map('intval', array_column($group, 'id')), 'daily', $dayAmounts);
        } else {
            foreach ($group as $s) {
                $rows[] = editor_row($s, [(int) $s['id']], 'same', []);
            }
        }
    }
    usort($rows, fn ($a, $b) => [$a['valid_from'] ?? '', $a['dose_time']] <=> [$b['valid_from'] ?? '', $b['dose_time']]);
    return $rows;
}

function editor_row(array $s, array $ids, string $mode, array $dayAmounts): array
{
    return [
        'ids'         => $ids,
        'dose_time'   => substr($s['dose_time'], 0, 5),
        'mode'        => $mode,
        'dose_amount' => $mode === 'same' ? num($s['dose_amount']) : '',
        'days_mask'   => $mode === 'same' ? (int) $s['days_mask'] : EVERY_DAY,
        'day_amounts' => $dayAmounts,
        'valid_from'  => $s['valid_from'],
        'valid_to'    => $s['valid_to'],
    ];
}

/** The [amount, days_mask] pairs to store for one editor row. */
function editor_row_doses(array $row): array
{
    if ($row['mode'] === 'same') {
        return [[(float) $row['dose_amount'], (int) $row['days_mask']]];
    }
    $byAmount = [];
    foreach ($row['day_amounts'] as $bit => $amount) {
        if ($amount !== '' && (float) $amount > 0) {
            $key = num($amount);
            $byAmount[$key] = ($byAmount[$key] ?? 0) | (int) $bit;
        }
    }
    $doses = [];
    foreach ($byAmount as $amount => $mask) {
        $doses[] = [(float) $amount, $mask];
    }
    return $doses;
}

/**
 * Human-readable dose times for a medication, combining rows that make up a
 * "different amount each day" schedule, e.g.
 * "18:00 · 5 tablets Mon, Wed, Fri; 3 tablets Tue, Thu, Sat, Sun".
 */
function schedule_lines(array $schedules, string $unit): array
{
    $lines = [];
    foreach (schedules_for_editing($schedules) as $row) {
        if ($row['mode'] === 'same') {
            $text = $row['dose_time'] . ' · ' . dose_label($row['dose_amount'], $unit) . ' · ' . days_label($row['days_mask']);
        } else {
            $parts = [];
            foreach (editor_row_doses($row) as [$amount, $mask]) {
                $parts[] = dose_label($amount, $unit) . ' ' . days_label($mask);
            }
            $text = $row['dose_time'] . ' · ' . implode('; ', $parts);
        }
        if ($row['valid_from'] || $row['valid_to']) {
            $from = $row['valid_from'] ? date('j M', strtotime($row['valid_from'])) : 'now';
            $to = $row['valid_to'] ? date('j M Y', strtotime($row['valid_to'])) : 'ongoing';
            $text .= " · $from – $to";
        }
        $lines[] = $text;
    }
    return $lines;
}

/**
 * Expand every scheduled dose for a user between two local dates (inclusive).
 *
 * Each slot: med, schedule, local (DateTimeImmutable in user tz), utc ('Y-m-d H:i:s'),
 * log (dose_logs row or null) and status: taken | skipped | missed | due | upcoming.
 */
function dose_slots(array $user, DateTimeImmutable $fromDay, DateTimeImmutable $toDay, bool $activeOnly = false, ?int $medId = null): array
{
    $tz = user_tz($user);       // where times are shown (the destination during a trip)
    $home = home_tz($user);     // doses belong to dates on the home calendar
    $plans = user_travel_plans((int) $user['id']);
    $utc = new DateTimeZone('UTC');
    $fromDay = $fromDay->setTimezone($tz)->setTime(0, 0);
    $toDay = $toDay->setTimezone($tz)->setTime(0, 0);
    $fromYmd = $fromDay->format('Y-m-d');
    $toYmd = $toDay->format('Y-m-d');
    // Time zone differences can move a dose onto the neighbouring date, so look one day either side.
    $scanFrom = new DateTimeImmutable($fromYmd . ' -1 day', $home);
    $scanTo = new DateTimeImmutable($toYmd . ' +1 day', $home);

    $sql = 'SELECT s.*, m.name, m.strength, m.dose_unit, m.colour, m.instructions, m.start_date, m.end_date, m.is_active
            FROM medication_schedules s
            JOIN medications m ON m.id = s.medication_id
            WHERE m.user_id = ? AND m.as_needed = 0
              AND m.start_date <= ? AND (m.end_date IS NULL OR m.end_date >= ?)
              AND (s.valid_from IS NULL OR s.valid_from <= ?) AND (s.valid_to IS NULL OR s.valid_to >= ?)';
    $params = [$user['id'], $scanTo->format('Y-m-d'), $scanFrom->format('Y-m-d'), $scanTo->format('Y-m-d'), $scanFrom->format('Y-m-d')];
    if ($activeOnly) {
        $sql .= ' AND m.is_active = 1';
    }
    if ($medId !== null) {
        $sql .= ' AND m.id = ?';
        $params[] = $medId;
    }
    $schedules = q($sql . ' ORDER BY s.dose_time, m.name', $params)->fetchAll();
    if (!$schedules) {
        return [];
    }

    $rangeStartUtc = $fromDay->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $rangeEndUtc = $toDay->modify('+2 days')->setTimezone($utc)->format('Y-m-d H:i:s');
    $logs = [];
    foreach (q(
        'SELECT * FROM dose_logs WHERE user_id = ? AND schedule_id IS NOT NULL AND scheduled_for >= ? AND scheduled_for < ?',
        [$user['id'], $rangeStartUtc, $rangeEndUtc]
    ) as $log) {
        $logs[$log['schedule_id'] . '|' . $log['scheduled_for']] = $log;
    }

    $now = new DateTimeImmutable('now', $utc);
    $missedAfter = (int) config('doses.missed_after_minutes', 120);
    $slots = [];

    for ($day = $scanFrom; $day <= $scanTo; $day = $day->modify('+1 day')) {
        $ymd = $day->format('Y-m-d');
        $bit = weekday_bit($day);
        foreach ($schedules as $s) {
            if (((int) $s['days_mask'] & $bit) === 0
                || $s['start_date'] > $ymd || ($s['end_date'] !== null && $s['end_date'] < $ymd)
                || ($s['valid_from'] !== null && $s['valid_from'] > $ymd) || ($s['valid_to'] !== null && $s['valid_to'] < $ymd)) {
                continue;
            }
            [$hh, $mm] = array_map('intval', explode(':', $s['dose_time']));
            [$moment, $trip] = dose_moment($ymd, $hh, $mm, $home, $plans);
            $local = $moment->setTimezone($tz);
            if ($local->format('Y-m-d') < $fromYmd || $local->format('Y-m-d') > $toYmd) {
                continue;
            }
            $slotUtc = $moment->setTimezone($utc);
            $utcStr = $slotUtc->format('Y-m-d H:i:s');
            $log = $logs[$s['id'] . '|' . $utcStr] ?? null;

            if ($log !== null) {
                $status = $log['status'];
            } elseif ($slotUtc > $now) {
                $status = 'upcoming';
            } elseif ($now->getTimestamp() - $slotUtc->getTimestamp() > $missedAfter * 60) {
                $status = 'missed';
            } else {
                $status = 'due';
            }
            $slots[] = ['schedule' => $s, 'local' => $local, 'utc' => $utcStr, 'log' => $log, 'status' => $status,
                // During travel: the dose's time back home, for reference.
                'home_time' => $trip || $tz->getName() !== $home->getName() ? $moment->setTimezone($home)->format('H:i') : null];
        }
    }
    usort($slots, fn ($a, $b) => [$a['local'], $a['schedule']['name']] <=> [$b['local'], $b['schedule']['name']]);
    return $slots;
}

/** Per-medication adherence for a set of slots: [med_id => [name, taken, skipped, missed, expected, pct]]. */
function adherence(array $slots): array
{
    $result = [];
    foreach ($slots as $slot) {
        if ($slot['status'] === 'upcoming' || $slot['status'] === 'due') {
            continue;
        }
        $id = $slot['schedule']['medication_id'];
        $result[$id] ??= ['name' => $slot['schedule']['name'], 'strength' => $slot['schedule']['strength'], 'colour' => $slot['schedule']['colour'],
            'taken' => 0, 'skipped' => 0, 'missed' => 0, 'expected' => 0];
        $result[$id][$slot['status']]++;
        $result[$id]['expected']++;
    }
    foreach ($result as &$row) {
        $row['pct'] = $row['expected'] ? (int) round(100 * $row['taken'] / $row['expected']) : null;
    }
    unset($row);
    uasort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $result;
}

/** As-needed doses logged by a user between two UTC instants. */
function prn_logs(int $userId, string $fromUtc, string $toUtc): array
{
    return q(
        'SELECT l.*, m.name, m.strength, m.dose_unit, m.colour
         FROM dose_logs l JOIN medications m ON m.id = l.medication_id
         WHERE l.user_id = ? AND l.schedule_id IS NULL AND l.taken_at >= ? AND l.taken_at < ?
         ORDER BY l.taken_at DESC',
        [$userId, $fromUtc, $toUtc]
    )->fetchAll();
}

/** Average units used per day by the current schedule (for stock estimates). */
function daily_usage(array $med, array $schedules): float
{
    if ($med['as_needed']) {
        return (float) ($med['prn_dose_amount'] ?? 0);
    }
    $perWeek = 0.0;
    foreach ($schedules as $s) {
        if ($s['valid_from'] !== null && $s['valid_from'] > gmdate('Y-m-d')) {
            continue;
        }
        $days = 0;
        foreach (WEEKDAYS as $bit => $_) {
            $days += ((int) $s['days_mask'] & $bit) ? 1 : 0;
        }
        $perWeek += (float) $s['dose_amount'] * $days;
    }
    return $perWeek / 7;
}

/** Days of stock left, or null if stock is not tracked / usage is zero. */
function stock_days_left(array $med, array $schedules): ?float
{
    if ($med['stock_count'] === null) {
        return null;
    }
    $usage = daily_usage($med, $schedules);
    return $usage > 0 ? (float) $med['stock_count'] / $usage : null;
}

function adjust_stock(int $medId, float $delta): void
{
    if ($delta != 0.0) {
        q('UPDATE medications SET stock_count = GREATEST(stock_count + ?, 0) WHERE id = ? AND stock_count IS NOT NULL', [$delta, $medId]);
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'taken' => 'Taken',
        'skipped' => 'Skipped',
        'missed' => 'Missed',
        'due' => 'Due now',
        default => 'Upcoming',
    };
}

/* ---------- Dose change history ---------- */

const CHANGE_KINDS = [
    'started' => 'Started', 'increased' => 'Increased', 'decreased' => 'Reduced', 'strength' => 'Strength changed',
    'timing' => 'Timing changed', 'as_needed' => 'Changed', 'stopped' => 'Stopped', 'restarted' => 'Restarted', 'note' => 'Note',
];

function record_med_change(int $userId, int $medId, string $effectiveDate, string $kind, string $summary, ?string $reason = null): void
{
    q('INSERT INTO medication_changes (user_id, medication_id, effective_date, kind, summary, reason) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, $medId, $effectiveDate, $kind, mb_substr($summary, 0, 500), $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null]);
}

function medication_changes(int $medId): array
{
    return q('SELECT * FROM medication_changes WHERE medication_id = ? ORDER BY effective_date DESC, id DESC', [$medId])->fetchAll();
}

/** Most recent change per medication: [med_id => row]. */
function latest_med_changes(int $userId): array
{
    $latest = [];
    // Latest by the date it took effect (not when it was typed in), then by entry order.
    foreach (q('SELECT c.* FROM medication_changes c
                WHERE c.user_id = ? AND NOT EXISTS (
                    SELECT 1 FROM medication_changes n WHERE n.medication_id = c.medication_id
                      AND (n.effective_date > c.effective_date OR (n.effective_date = c.effective_date AND n.id > c.id)))', [$userId]) as $c) {
        $latest[$c['medication_id']] = $c;
    }
    return $latest;
}

/** "20mg" -> [20.0, 'mg']; null if the strength isn't a simple number and unit. */
function parse_strength(?string $strength): ?array
{
    if ($strength !== null && preg_match('~^\s*(\d+(?:\.\d+)?)\s*([a-zµ%]+(?:/[a-z0-9.]+)?)\s*$~i', $strength, $m)) {
        return [(float) $m[1], strtolower($m[2])];
    }
    return null;
}

/** How a medicine is taken, in a few words: "1 tablet a day at 08:00" or "as needed". */
function medication_regimen(array $med, array $schedules): string
{
    if ($med['as_needed']) {
        return 'as needed';
    }
    return $schedules ? implode('; ', schedule_lines($schedules, $med['dose_unit'])) : 'no dose times';
}

/**
 * Describe what changed between two versions of a medication.
 * @return list<array{0: string, 1: string}> [kind, summary]
 */
function describe_med_changes(array $old, array $oldSchedules, array $new, array $newSchedules): array
{
    $unit = $new['dose_unit'];
    if ((bool) $old['as_needed'] !== (bool) $new['as_needed']) {
        return [['as_needed', $new['as_needed']
            ? 'Changed to as needed (was ' . medication_regimen($old, $oldSchedules) . ')'
            : 'Changed from as needed to regular doses: ' . medication_regimen($new, $newSchedules)]];
    }

    $oldDaily = daily_usage($old, $oldSchedules);
    $newDaily = daily_usage($new, $newSchedules);
    $strengthChanged = mb_strtolower(trim((string) $old['strength'])) !== mb_strtolower(trim((string) $new['strength']));
    $dailyChanged = abs($oldDaily - $newDaily) > 0.001;
    $timingChanged = schedule_lines($oldSchedules, $old['dose_unit']) !== schedule_lines($newSchedules, $unit);
    $everyDay = !array_filter($newSchedules, fn ($s) => (int) $s['days_mask'] !== EVERY_DAY)
        && !array_filter($oldSchedules, fn ($s) => (int) $s['days_mask'] !== EVERY_DAY);
    // Weekly patterns (e.g. 5mg Mon/Wed/Fri, 3mg other days) read best as weekly totals.
    $factor = $everyDay ? 1 : 7;
    $perDay = $everyDay ? ' a day' : ' a week';
    $oldDaily *= $factor;
    $newDaily *= $factor;
    $amount = fn (float $n) => num(round($n, 2));
    // Only whole units (tablets, capsules, ...) can be multiplied by their strength;
    // a liquid's strength is a concentration (e.g. 250mg/5ml).
    $countable = in_array($unit, ['tablet', 'capsule', 'sachet', 'patch', 'suppository', 'lozenge'], true);

    if ($strengthChanged || $dailyChanged) {
        $os = parse_strength($old['strength']);
        $ns = parse_strength($new['strength']);
        if (!$new['as_needed'] && $countable && $os && $ns && $os[1] === $ns[1] && !str_contains($ns[1], '/') && $oldDaily > 0 && $newDaily > 0) {
            $oldTotal = $os[0] * $oldDaily;
            $newTotal = $ns[0] * $newDaily;
            $kind = $newTotal > $oldTotal + 0.0001 ? 'increased' : ($newTotal < $oldTotal - 0.0001 ? 'decreased' : 'strength');
            $words = ['increased' => 'Increased', 'decreased' => 'Reduced', 'strength' => 'Changed'][$kind];
            $summary = $kind === 'strength'
                ? "Changed to {$new['strength']} (same total of " . $amount($newTotal) . $ns[1] . $perDay . ')'
                : "$words from " . $amount($oldTotal) . $os[1] . ' to ' . $amount($newTotal) . $ns[1] . $perDay;
            $detail = [];
            if ($strengthChanged) {
                $detail[] = "now {$new['strength']} " . ($unit === 'tablet' || $unit === 'capsule' ? $unit . 's' : 'strength');
            }
            if ($dailyChanged) {
                $detail[] = dose_label($amount($newDaily), $unit) . $perDay;
            }
            return [[$kind, $summary . ($detail && $kind !== 'strength' ? ' (' . implode(', ', $detail) . ')' : '')]];
        }
        if ($dailyChanged) {
            $kind = $newDaily > $oldDaily ? 'increased' : 'decreased';
            $changes = [[$kind, ($kind === 'increased' ? 'Increased' : 'Reduced') . ' from ' . dose_label($amount($oldDaily), $unit)
                . ' to ' . dose_label($amount($newDaily), $unit) . $perDay]];
            if ($strengthChanged) {
                $changes[] = ['strength', "Strength changed from " . ($old['strength'] ?: 'not set') . " to " . ($new['strength'] ?: 'not set')];
            }
            return $changes;
        }
        return [['strength', 'Strength changed from ' . ($old['strength'] ?: 'not set') . ' to ' . ($new['strength'] ?: 'not set')]];
    }
    if ($timingChanged && !$new['as_needed']) {
        return [['timing', 'Dose times changed to ' . medication_regimen($new, $newSchedules) . ' (was ' . medication_regimen($old, $oldSchedules) . ')']];
    }
    return [];
}
