<?php
declare(strict_types=1);

const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 4 => 'Wed', 8 => 'Thu', 16 => 'Fri', 32 => 'Sat', 64 => 'Sun'];
const EVERY_DAY = 127;

const MED_FORMS = ['tablet', 'capsule', 'liquid', 'inhaler', 'injection', 'patch', 'drops', 'cream', 'gel', 'spray', 'suppository', 'sachet', 'other'];
const DOSE_UNITS = ['tablet', 'capsule', 'ml', 'mg', 'mcg', 'g', 'puff', 'drop', 'unit', 'patch', 'sachet', 'spray', 'pump', 'application', 'injection', 'suppository', 'lozenge'];
// Medication colours, used as CSS classes (.dot-<colour>) so no inline styles are needed.
// How a medicine is paid for. Only NHS prescriptions have the set charge that a PPC or exemption covers.
const SUPPLY_TYPES = [
    'nhs' => 'NHS prescription',
    'private' => 'Private prescription',
    'otc' => 'Bought without a prescription',
];

// Parts of the day: Today groups doses under these, and the schedule editor offers their usual times.
// A dose belongs to the block its time falls in (before 12:00 morning, before 17:00 afternoon, then evening).
const DOSE_BLOCKS = [
    'morning'   => ['label' => 'Morning', 'time' => '08:00', 'before' => '12:00'],
    'afternoon' => ['label' => 'Afternoon', 'time' => '13:00', 'before' => '17:00'],
    'evening'   => ['label' => 'Evening', 'time' => '18:00', 'before' => '24:00'],
];

// How long a medicine is taken for. Short courses and one-off doses stop by themselves once finished.
const MED_COURSES = [
    'ongoing' => 'Ongoing',
    'short'   => 'Short course',
    'once'    => 'One-off dose',
];

// One-tap common supplements on the "Add supplement" page: [name, strength, form, dose unit].
// Strengths only where the NHS gives a general amount; otherwise people enter what's on the packet.
const SUPPLEMENT_PRESETS = [
    ['Vitamin D', '10 micrograms', 'tablet', 'tablet'],
    ['Multivitamin', '', 'tablet', 'tablet'],
    ['Folic acid', '400 micrograms', 'tablet', 'tablet'],
    ['Vitamin B12', '', 'tablet', 'tablet'],
    ['Iron', '', 'tablet', 'tablet'],
    ['Vitamin C', '', 'tablet', 'tablet'],
    ['Calcium and vitamin D', '', 'tablet', 'tablet'],
    ['Magnesium', '', 'tablet', 'tablet'],
    ['Zinc', '', 'tablet', 'tablet'],
    ['Omega-3 fish oil', '', 'capsule', 'capsule'],
    ['Probiotic', '', 'capsule', 'capsule'],
    ['Glucosamine', '', 'tablet', 'tablet'],
];

const MED_COLOURS = ['blue', 'red', 'green', 'orange', 'purple', 'pink', 'teal', 'yellow', 'grey'];

function weekday_bit(DateTimeInterface $date): int
{
    return 1 << ((int) $date->format('N') - 1);
}

/**
 * Whether a medicine's supply has expired or expires within 30 days.
 * @return array{0: ?string, 1: string} ['expired' | 'soon' | null, wording]
 */
function expiry_status(?string $expiry, DateTimeImmutable $today): array
{
    if (!$expiry) {
        return [null, ''];
    }
    $date = new DateTimeImmutable($expiry, $today->getTimezone());
    $days = (int) $today->diff($date)->format('%r%a');
    return match (true) {
        $days < 0   => ['expired', 'Expired ' . $date->format('j M Y')],
        $days === 0 => ['expired', 'Expires today'],
        $days <= 30 => ['soon', "Expires in $days day" . ($days === 1 ? '' : 's') . ' (' . $date->format('j M') . ')'],
        default     => [null, 'Expires ' . $date->format('j M Y')],
    };
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
        'SELECT * FROM medications WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . (private_meds_hidden() ? ' AND is_private = 0' : '') . ' ORDER BY is_active DESC, name',
        [$userId]
    )->fetchAll();
}

/** Private medicines are never shown to someone looking at a shared Pillbox. */
function private_meds_hidden(): bool
{
    return isset(current_user()['_acting']);
}

/** A medicine's name, or "a private medicine" where it might be seen by others (notifications, emails). */
function public_med_name(array $med): string
{
    return !empty($med['is_private']) ? 'Private medicine' : trim($med['name'] . ' ' . ($med['strength'] ?? ''));
}

function find_medication(int $id, int $userId): ?array
{
    return q('SELECT * FROM medications WHERE id = ? AND user_id = ?' . (private_meds_hidden() ? ' AND is_private = 0' : ''), [$id, $userId])->fetch() ?: null;
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

/**
 * The schedule to show in the editor and compare changes against. When a change has been
 * split to start tomorrow (because today's doses were already logged), the version ending
 * today is left out so it isn't edited again.
 */
function editing_schedules(int $medId, string $todayYmd): array
{
    $tomorrow = (new DateTimeImmutable($todayYmd))->modify('+1 day')->format('Y-m-d');
    $rows = current_schedules($medId, $todayYmd);
    $split = array_filter($rows, fn ($s) => $s['valid_from'] === $tomorrow) && array_filter($rows, fn ($s) => $s['valid_to'] === $todayYmd);
    return $split ? array_values(array_filter($rows, fn ($s) => $s['valid_to'] !== $todayYmd)) : $rows;
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
    $text = substr($schedule['dose_time'], 0, 5) . ' · ' . dose_label($schedule['dose_amount'], $unit) . ' · ' . schedule_days_text($schedule);
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
        $groups[substr($s['dose_time'], 0, 5) . '|' . $s['valid_from'] . '|' . $s['valid_to'] . '|' . ($s['every_days'] ?? '') . '|' . ($s['anchor_date'] ?? '') . '|' . cycle_key($s) . '|' . ($s['cycle_phase'] ?? '')][] = $s;
    }
    $rows = [];
    foreach ($groups as $group) {
        $covered = 0;
        $disjoint = true;
        foreach ($group as $s) {
            $disjoint = $disjoint && ($covered & (int) $s['days_mask']) === 0;
            $covered |= (int) $s['days_mask'];
        }
        if (count($group) > 1 && $disjoint && empty($group[0]['every_days']) && empty($group[0]['cycle_phase'])) {
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
        'every_days'  => !empty($s['every_days']) ? (int) $s['every_days'] : null,
        'anchor_date' => $s['anchor_date'] ?? null,
        'cycle_phase' => $s['cycle_phase'] ?? null,
        'cycle_length' => !empty($s['cycle_length']) ? (int) $s['cycle_length'] : null,
        'cycle_from'  => !empty($s['cycle_from']) ? (int) $s['cycle_from'] : null,
        'cycle_to'    => !empty($s['cycle_to']) ? (int) $s['cycle_to'] : null,
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
function schedule_lines(array $schedules, string $unit, bool $now = true): array
{
    $lines = [];
    foreach (schedules_for_editing($schedules) as $row) {
        if ($row['mode'] === 'same') {
            $text = $row['dose_time'] . ' · ' . dose_label($row['dose_amount'], $unit) . ' · ' . schedule_days_text($row, $now);
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

    $sql = 'SELECT s.*, m.user_id, m.name, m.strength, m.dose_unit, m.colour, m.instructions, m.start_date, m.end_date, m.is_active, m.course, m.form, m.is_private
            FROM medication_schedules s
            JOIN medications m ON m.id = s.medication_id
            WHERE m.user_id = ? AND m.as_needed = 0
              AND m.start_date <= ? AND (m.end_date IS NULL OR m.end_date >= ?)
              AND (s.valid_from IS NULL OR s.valid_from <= ?) AND (s.valid_to IS NULL OR s.valid_to >= ?)';
    $params = [$user['id'], $scanTo->format('Y-m-d'), $scanFrom->format('Y-m-d'), $scanTo->format('Y-m-d'), $scanFrom->format('Y-m-d')];
    if ($activeOnly) {
        $sql .= ' AND m.is_active = 1';
    }
    if (private_meds_hidden()) {
        $sql .= ' AND m.is_private = 0';
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
            if (!schedule_on_day($s, $ymd, $bit)
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

/** Average units used per day by the schedule in effect on $asOf (default today), for stock estimates. */
function daily_usage(array $med, array $schedules, ?string $asOf = null): float
{
    if ($med['as_needed']) {
        return (float) ($med['prn_dose_amount'] ?? 0);
    }
    $asOf ??= gmdate('Y-m-d');
    $perWeek = 0.0;
    foreach ($schedules as $s) {
        if (($s['valid_from'] !== null && $s['valid_from'] > $asOf) || ($s['valid_to'] !== null && $s['valid_to'] < $asOf)) {
            continue;
        }
        if (!empty($s['every_days'])) {
            $perWeek += (float) $s['dose_amount'] * 7 / (int) $s['every_days'];
            continue;
        }
        if (($s['cycle_phase'] ?? null) === 'luteal') {
            $perWeek += (float) $s['dose_amount'] * 7 / 2; // about half of each cycle
            continue;
        }
        if (is_cycle_schedule($s)) {
            $length = $s['cycle_phase'] === 'month' ? 30.44 : (int) $s['cycle_length'];
            $perWeek += (float) $s['dose_amount'] * 7 * ((int) $s['cycle_to'] - (int) $s['cycle_from'] + 1) / $length;
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
        'missed' => 'Not logged',
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

/**
 * How a medicine is taken, in a few words: "1 tablet a day at 08:00" or "as needed".
 * $now: include where it is today ("next 3 Nov", "today is day 9"); off for the change history.
 */
function medication_regimen(array $med, array $schedules, bool $now = true): string
{
    if ($med['as_needed']) {
        return 'as needed';
    }
    return $schedules ? implode('; ', schedule_lines($schedules, $med['dose_unit'], $now)) : 'no dose times';
}

/**
 * Describe what changed between two versions of a medication.
 * @return list<array{0: string, 1: string}> [kind, summary]
 */
function describe_med_changes(array $old, array $oldSchedules, array $new, array $newSchedules, ?string $asOf = null): array
{
    $unit = $new['dose_unit'];
    if ((bool) $old['as_needed'] !== (bool) $new['as_needed']) {
        return [['as_needed', $new['as_needed']
            ? 'Changed to as needed (was ' . medication_regimen($old, $oldSchedules, false) . ')'
            : 'Changed from as needed to regular doses: ' . medication_regimen($new, $newSchedules, false)]];
    }

    // Every-N-days schedules (e.g. injections every 3 weeks) read best as the whole regimen.
    if (array_filter([...$oldSchedules, ...$newSchedules], fn ($s) => !empty($s['every_days']) || is_cycle_schedule($s))) {
        $changes = [];
        if (mb_strtolower(trim((string) $old['strength'])) !== mb_strtolower(trim((string) $new['strength']))) {
            $changes[] = ['strength', 'Strength changed from ' . ($old['strength'] ?: 'not set') . ' to ' . ($new['strength'] ?: 'not set')];
        }
        if (schedule_lines($oldSchedules, $old['dose_unit'], false) !== schedule_lines($newSchedules, $unit, false)) {
            $o = daily_usage($old, $oldSchedules, $asOf);
            $n = daily_usage($new, $newSchedules, $asOf);
            $changes[] = [abs($o - $n) < 0.0001 ? 'timing' : ($n > $o ? 'increased' : 'decreased'),
                'Changed to ' . medication_regimen($new, $newSchedules, false) . ' (was ' . medication_regimen($old, $oldSchedules, false) . ')'];
        }
        return $changes;
    }

    // Both compared on the same day: the day the change takes effect.
    $oldDaily = daily_usage($old, $oldSchedules, $asOf);
    $newDaily = daily_usage($new, $newSchedules, $asOf);
    $strengthChanged = mb_strtolower(trim((string) $old['strength'])) !== mb_strtolower(trim((string) $new['strength']));
    $dailyChanged = abs($oldDaily - $newDaily) > 0.001;
    $timingChanged = schedule_lines($oldSchedules, $old['dose_unit'], false) !== schedule_lines($newSchedules, $unit, false);
    $everyDay = !array_filter([...$newSchedules, ...$oldSchedules], fn ($s) => (int) $s['days_mask'] !== EVERY_DAY || !empty($s['every_days']) || !empty($s['cycle_phase']));
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
        return [['timing', 'Dose times changed to ' . medication_regimen($new, $newSchedules, false) . ' (was ' . medication_regimen($old, $oldSchedules, false) . ')']];
    }
    return [];
}

/** Which part of the day a dose time ("HH:MM") is in. */
function dose_block(string $time): string
{
    foreach (DOSE_BLOCKS as $key => $block) {
        if (substr($time, 0, 5) < $block['before']) {
            return $key;
        }
    }
    return 'evening';
}

/**
 * Record a scheduled dose as taken or skipped (replacing any earlier record for it), keeping
 * stock in step. $takenAt is UTC.
 */
/** "4 hours", "1 hour 30 minutes", "45 minutes" */
function gap_label(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return trim(($h ? $h . ' hour' . ($h === 1 ? '' : 's') : '') . ($m ? ' ' . $m . ' minute' . ($m === 1 ? '' : 's') : ''));
}

/**
 * A dose at $takenAtUtc would be too soon after (or before) another taken dose of the same medicine,
 * by its minimum time between doses. $excludeLogId: the record being changed, if any.
 * @return array{last: string, next: string, minutes: int}|null UTC times of the nearby dose and of the earliest allowed
 */
function dose_too_soon(array $med, string $takenAtUtc, ?int $excludeLogId = null): ?array
{
    $minutes = (int) ($med['min_interval_minutes'] ?? 0);
    if ($minutes <= 0) {
        return null;
    }
    $t = strtotime($takenAtUtc . ' UTC');
    $near = q("SELECT taken_at FROM dose_logs WHERE medication_id = ? AND status = 'taken' AND taken_at > ? AND taken_at < ? AND id <> ?
               ORDER BY taken_at DESC LIMIT 1",
        [$med['id'], gmdate('Y-m-d H:i:s', $t - $minutes * 60), gmdate('Y-m-d H:i:s', $t + $minutes * 60), $excludeLogId ?? 0])->fetchColumn();
    return $near ? ['last' => (string) $near, 'next' => gmdate('Y-m-d H:i:s', strtotime($near . ' UTC') + $minutes * 60), 'minutes' => $minutes] : null;
}

/** "Too soon: Ibuprofen was taken at 13:05 ..." */
function too_soon_text(array $med, array $soon): string
{
    $day = fn (string $utc) => local_time($utc, 'Y-m-d') === local_today()->format('Y-m-d') ? local_time($utc, 'H:i') : local_time($utc, 'D j M H:i');
    return "Too soon: {$med['name']} was taken at " . $day($soon['last']) . ', and it should be at least ' . gap_label($soon['minutes'])
        . ' between doses, so the next one is from ' . $day($soon['next']) . '.';
}

function record_dose(int $userId, array $schedule, string $slotUtc, string $status, string $takenAt, ?string $site = null): void
{
    $amount = $status === 'taken' ? (float) $schedule['dose_amount'] : 0.0;
    $site = $status === 'taken' && $site !== null && isset(INJECTION_SITES[$site]) ? $site : null;
    transaction(function () use ($userId, $schedule, $slotUtc, $status, $amount, $takenAt, $site) {
        $existing = q('SELECT * FROM dose_logs WHERE schedule_id = ? AND scheduled_for = ? FOR UPDATE', [$schedule['id'], $slotUtc])->fetch();
        if ($existing) {
            if ($existing['status'] === 'taken') {
                adjust_stock((int) $schedule['med_id'], (float) $existing['amount']);
            }
            q('UPDATE dose_logs SET status = ?, amount = ?, taken_at = ?, site = ? WHERE id = ?', [$status, $amount, $takenAt, $site, $existing['id']]);
        } else {
            q(
                'INSERT INTO dose_logs (user_id, medication_id, schedule_id, scheduled_for, status, amount, taken_at, site) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $schedule['med_id'], $schedule['id'], $slotUtc, $status, $amount, $takenAt, $site]
            );
        }
        if ($status === 'taken') {
            adjust_stock((int) $schedule['med_id'], -$amount);
        }
    });
}

/** Each medicine's most recent "collected" stock event, keyed by medication id. */
function last_collected(int $userId): array
{
    $out = [];
    foreach (q(
        "SELECT e.* FROM stock_events e
         JOIN (SELECT medication_id, MAX(id) AS id FROM stock_events WHERE user_id = ? AND kind = 'collected' GROUP BY medication_id) x ON x.id = e.id",
        [$userId]
    ) as $e) {
        $out[$e['medication_id']] = $e;
    }
    return $out;
}

/** "Day 3 of 7" or "One-off dose" for short courses on $day; null for ongoing medicines. */
function course_progress(array $med, DateTimeImmutable $day): ?string
{
    if (($med['course'] ?? 'ongoing') === 'ongoing' || empty($med['end_date'])) {
        return null;
    }
    if ($med['course'] === 'once') {
        return 'One-off dose';
    }
    $start = new DateTimeImmutable($med['start_date']);
    $total = $start->diff(new DateTimeImmutable($med['end_date']))->days + 1;
    $ymd = $day->format('Y-m-d');
    return match (true) {
        $ymd < $med['start_date'] => "$total-day course, starts " . $start->format('j M'),
        $ymd > $med['end_date'] => "$total-day course, finished",
        default => 'Day ' . ($start->diff(new DateTimeImmutable($ymd))->days + 1) . " of $total",
    };
}

/**
 * Short courses and one-off doses move to stopped medicines once their last day is over
 * (a day later, so "Missed yesterday" can still show their last doses). History is kept.
 */
function finish_courses(array $user): void
{
    $cutoff = (new DateTimeImmutable('today', home_tz($user)))->modify('-1 day')->format('Y-m-d');
    foreach (q("SELECT id, end_date FROM medications WHERE user_id = ? AND is_active = 1 AND course <> 'ongoing' AND end_date IS NOT NULL AND end_date < ?",
        [$user['id'], $cutoff]) as $m) {
        q('UPDATE medications SET is_active = 0 WHERE id = ?', [$m['id']]);
        record_med_change((int) $user['id'], (int) $m['id'], $m['end_date'], 'stopped', 'Course finished');
    }
}

/** Is this schedule due on this day? Weekdays, or every N days from its first dose. */
function schedule_on_day(array $s, string $ymd, ?int $bit = null): bool
{
    if (($s['cycle_phase'] ?? null) === 'luteal') {
        // Only in the (known or predicted) 2 weeks before a period.
        return is_luteal_day((int) ($s['user_id'] ?? current_user()['id'] ?? 0), $ymd);
    }
    if (is_cycle_schedule($s)) {
        $day = cycle_day_of($s, $ymd);
        return $day !== null && $day >= (int) $s['cycle_from'] && $day <= (int) $s['cycle_to'];
    }
    if (!empty($s['every_days'])) {
        $anchor = (string) ($s['anchor_date'] ?: $s['valid_from'] ?: $s['start_date'] ?? $ymd);
        if ($ymd < $anchor) {
            return false;
        }
        return (new DateTimeImmutable($anchor))->diff(new DateTimeImmutable($ymd))->days % (int) $s['every_days'] === 0;
    }
    return ((int) $s['days_mask'] & ($bit ?? weekday_bit(new DateTimeImmutable($ymd)))) !== 0;
}

/** A repeating cycle (e.g. sequential HRT): some days of every N-day cycle, or of each calendar month. */
function is_cycle_schedule(array $s): bool
{
    return in_array($s['cycle_phase'] ?? null, ['cycle', 'month'], true) && !empty($s['cycle_from']) && !empty($s['cycle_to'])
        && ($s['cycle_phase'] === 'month' || !empty($s['cycle_length']));
}

function cycle_key(array $s): string
{
    return is_cycle_schedule($s) ? ($s['cycle_length'] ?? '') . '/' . $s['cycle_from'] . '-' . $s['cycle_to'] : '';
}

/** Which day of its cycle $ymd is (1 = the first), or null before the cycle starts. */
function cycle_day_of(array $s, string $ymd): ?int
{
    if (($s['cycle_phase'] ?? null) === 'month') {
        return (int) substr($ymd, 8, 2);
    }
    $anchor = (string) ($s['anchor_date'] ?: $s['valid_from'] ?: $s['start_date'] ?? $ymd);
    if ($ymd < $anchor) {
        return null;
    }
    return (new DateTimeImmutable($anchor))->diff(new DateTimeImmutable($ymd))->days % (int) $s['cycle_length'] + 1;
}

function ordinal(int $n): string
{
    return $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
}

/** "every 3 weeks" / "every 10 days". */
function every_days_label(int $days): string
{
    return $days % 7 === 0 ? ($days === 7 ? 'every week' : 'every ' . ($days / 7) . ' weeks') : ($days === 1 ? 'every day' : "every $days days");
}

/** The next dose day of an every-N-days schedule, on or after $from. */
function next_interval_day(array $s, DateTimeImmutable $from): ?DateTimeImmutable
{
    if (empty($s['every_days'])) {
        return null;
    }
    $anchor = new DateTimeImmutable((string) ($s['anchor_date'] ?: $s['valid_from'] ?: $from->format('Y-m-d')));
    $fromDay = new DateTimeImmutable($from->format('Y-m-d'));
    if ($fromDay <= $anchor) {
        return $anchor;
    }
    $gap = $anchor->diff($fromDay)->days % (int) $s['every_days'];
    return $gap === 0 ? $fromDay : $fromDay->modify('+' . ((int) $s['every_days'] - $gap) . ' days');
}

/** Which days a schedule (or editor row) is on, in words; $now adds the next dose or today's cycle day. */
function schedule_days_text(array $s, bool $now = true): string
{
    if (($s['cycle_phase'] ?? null) === 'luteal') {
        return 'only in the 2 weeks before a period';
    }
    if (is_cycle_schedule($s)) {
        $from = (int) $s['cycle_from'];
        $to = (int) $s['cycle_to'];
        if ($s['cycle_phase'] === 'month') {
            return 'the ' . ordinal($from) . ($to > $from ? ' to ' . ordinal($to) : '') . ' of each month';
        }
        $today = $now ? cycle_day_of($s, local_today()->format('Y-m-d')) : null;
        return ($to > $from ? "days $from to $to" : "day $from") . ' of each ' . (int) $s['cycle_length'] . '-day cycle'
            . ($today !== null ? " (today is day $today)" : ($now || !$s['anchor_date'] ? '' : ', day 1 was ' . date('j M Y', strtotime((string) $s['anchor_date']))))
            . ($now && $today === null && $s['anchor_date'] ? ', starting ' . date('j M', strtotime((string) $s['anchor_date'])) : '');
    }
    if (!empty($s['every_days'])) {
        $next = $now ? next_interval_day($s, local_today()) : null;
        return every_days_label((int) $s['every_days']) . ($next ? ', next ' . $next->format('j M') : '');
    }
    return days_label((int) $s['days_mask']);
}

/**
 * Prescription timing for a medicine with tracked stock: when supply runs out, when to ask for
 * the next prescription, and (controlled drugs) the date a written prescription must be collected
 * by: Schedule 2 to 4 controlled drug prescriptions are only valid for 28 days.
 * @return array{runs_out: ?DateTimeImmutable, request: ?DateTimeImmutable, collect_by: ?DateTimeImmutable}
 */
function prescription_dates(array $med, array $schedules, DateTimeImmutable $today): array
{
    $days = stock_days_left($med, $schedules);
    $runsOut = $days !== null ? $today->modify('+' . (int) floor($days) . ' days') : null;
    $lead = $med['controlled'] ? 7 : max(7, (int) $med['low_stock_days']);
    return [
        'runs_out' => $runsOut,
        'request' => $runsOut?->modify("-$lead days"),
        'collect_by' => $med['controlled'] && $med['rx_written'] ? (new DateTimeImmutable($med['rx_written']))->modify('+27 days') : null,
    ];
}

/** Changes to some of someone's medicines, as chart markers: [[unix time, label], ...]. $match decides by medicine name and reason. */
function medication_change_markers(int $userId, callable $match): array
{
    $out = [];
    foreach (q("SELECT c.effective_date, c.kind, c.summary, m.name, m.reason FROM medication_changes c JOIN medications m ON m.id = c.medication_id
                WHERE c.user_id = ? AND c.kind IN ('started', 'increased', 'decreased', 'strength', 'stopped', 'restarted') ORDER BY c.effective_date", [$userId]) as $c) {
        if ($match($c['name'] . ' ' . $c['reason'])) {
            $out[] = [strtotime($c['effective_date'] . ' 12:00 UTC'), CHANGE_KINDS[$c['kind']] . ': ' . $c['name'] . ', ' . lcfirst($c['summary'])];
        }
    }
    return $out;
}
