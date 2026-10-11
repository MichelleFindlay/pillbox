<?php
declare(strict_types=1);

/*
 * Checks and renewals: things that come round every few months or years. Each has
 * the date it was last done; the next due date is worked out from that and its
 * interval, or from a rule ('autumn' for seasonal vaccines, 'screening' for NHS
 * programmes based on date of birth). A due date set by hand overrides both until
 * it's marked done.
 */

// NHS England screening programmes. 'to' is the last age invited; 'sexes' the profile sexes it's set up for
// (with 'Other', the user ticks the ones that apply); 'who' explains who it's for.
const SCREENING_PROGRAMMES = [
    'cervical' => ['label' => 'Cervical screening', 'sexes' => ['female'], 'who' => 'if you have a cervix', 'from' => 25, 'to' => 64, 'interval' => 1826,
        'tip' => 'Every 5 years from 25 to 64 for anyone with a cervix. If HPV was found last time, you will be invited sooner: follow your letter.'],
    'breast'   => ['label' => 'Breast screening', 'sexes' => ['female'], 'who' => 'if you have breasts or breast tissue', 'from' => 50, 'to' => 70, 'interval' => 1095,
        'tip' => 'A mammogram every 3 years from 50 up to your 71st birthday. Your first invitation comes some time between 50 and 53.',
        'after' => 'After 71 you are no longer invited, but you can ask your local breast screening service for an appointment every 3 years.'],
    'bowel'    => ['label' => 'Bowel screening', 'sexes' => ['female', 'male'], 'who' => 'everyone aged 50 to 74', 'from' => 50, 'to' => 74, 'interval' => 730,
        'tip' => 'A home test kit (FIT) every 2 years from 50 to 74.',
        'after' => 'After 74 you are no longer sent a kit, but you can ask for one every 2 years from the free bowel screening helpline.'],
    'aaa'      => ['label' => 'AAA screening', 'sexes' => ['male'], 'who' => 'if you were assigned male at birth', 'from' => 65, 'to' => 65, 'interval' => null,
        'tip' => 'A one-off ultrasound scan for an abdominal aortic aneurysm, offered to men in the year they turn 65.',
        'after' => 'If you are over 65 and have never been screened, you can ask your local AAA screening service.'],
];

const SEXES = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other'];

// How a check is tracked: a due date, an expiry date, a stock count, a fill level, or a kit's contents.
const CHECK_TRACKS = [
    'due'    => 'Due every so often',
    'expiry' => 'Expiry date',
    'stock'  => 'Stock to keep up',
    'level'  => 'Fill level (e.g. sharps bin)',
    'kit'    => 'Kit with contents to check',
];
const FILL_LEVELS = [0 => 'Empty', 25 => 'A quarter full', 50 => 'Half full', 75 => 'Three-quarters full', 100 => 'Full (at the fill line)'];

/** Does this screening programme apply to the user, from their profile? */
function screening_applies(array $user, string $kind): bool
{
    // The body inventory, when filled in, decides (anatomy, not gender).
    if (body_inventory($user) !== null) {
        return inventory_screening($user, $kind);
    }
    if ($user['sex'] === 'other') {
        return in_array($kind, explode(',', (string) $user['screening_programmes']), true);
    }
    return in_array($user['sex'], SCREENING_PROGRAMMES[$kind]['sexes'] ?? [], true);
}

/** A check preset (or screening programme) with every field filled in. */
function check_preset(string $area, string $kind): ?array
{
    $p = $area === 'screening' ? (isset(SCREENING_PROGRAMMES[$kind]) ? SCREENING_PROGRAMMES[$kind] + ['rule' => 'screening'] : null)
        : (CARE_AREAS[$area]['checks'][$kind] ?? null);
    return $p === null ? null : $p + ['interval' => null, 'rule' => null, 'tip' => null, 'track' => 'due', 'unit' => null, 'low' => null, 'items' => null];
}

/** A user's checks: one area or all, without hidden screening programmes unless asked. */
function health_checks(int $userId, ?string $area = null, bool $withHidden = false): array
{
    $sql = 'SELECT * FROM health_checks WHERE user_id = ?' . ($area !== null ? ' AND area = ?' : '') . ($withHidden ? '' : ' AND hidden = 0') . ' ORDER BY id';
    return q($sql, $area !== null ? [$userId, $area] : [$userId])->fetchAll();
}

function find_health_check(int $id, int $userId): ?array
{
    return q('SELECT * FROM health_checks WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
}

function add_preset_check(int $userId, string $area, string $kind): ?array
{
    $p = check_preset($area, $kind);
    if (!$p) {
        return null;
    }
    q('INSERT INTO health_checks (user_id, area, kind, label, interval_days, rule, track, unit, low_at, items, tip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $area, $kind, $p['label'], $p['interval'], $p['rule'], $p['track'], $p['unit'], $p['low'], $p['items'], $p['tip']]);
    return $p;
}

/**
 * After the profile's sex or chosen programmes change: hide programmes that no longer
 * apply (unless one has been recorded), and show the ones that now do.
 */
function apply_screening_profile(array $user): void
{
    foreach (q("SELECT * FROM health_checks WHERE user_id = ? AND area = 'screening'", [$user['id']]) as $c) {
        $applies = screening_applies($user, $c['kind']);
        if ($applies === (bool) $c['hidden'] && ($applies || !$c['last_done'])) {
            q('UPDATE health_checks SET hidden = ? WHERE id = ?', [$applies ? 0 : 1, $c['id']]);
        }
    }
    sync_screening($user);
}

/** Make sure the screening programmes for the user's sex are listed (once; removed ones stay hidden). */
function sync_screening(array $user): void
{
    if (!$user['sex'] && body_inventory($user) === null) {
        return;
    }
    $have = q("SELECT kind FROM health_checks WHERE user_id = ? AND area = 'screening'", [$user['id']])->fetchAll(PDO::FETCH_COLUMN);
    foreach (SCREENING_PROGRAMMES as $kind => $p) {
        if (screening_applies($user, $kind) && !in_array($kind, $have, true)) {
            add_preset_check((int) $user['id'], 'screening', $kind);
        }
    }
}

/**
 * When a check is next due, and a note when there's no date or something to explain.
 * @return array{0: ?DateTimeImmutable, 1: string}
 */
function check_next_due(array $check, array $user, DateTimeImmutable $today): array
{
    $tz = $today->getTimezone();
    $date = fn (string $ymd) => new DateTimeImmutable($ymd, $tz);
    $last = $check['last_done'] ? $date($check['last_done']) : null;

    switch ($check['track'] ?? 'due') {
        case 'expiry':
            if ($check['due_date']) {
                return [$date($check['due_date']), ''];
            }
            // "Use within N days of opening": from the day it was opened.
            if ($check['interval_days']) {
                return $last ? [$last->modify('+' . (int) $check['interval_days'] . ' days'), ''] : [null, 'Mark it opened when you start a new one.'];
            }
            return [null, check_lengths($check) ? 'Add when your certificate starts.' : 'Add the expiry date.'];
        case 'stock':
            if ($check['quantity'] === null) {
                return [null, 'Add how many you have.'];
            }
            $left = stock_left($check, $today);
            $low = (float) ($check['low_at'] ?? 0);
            if ($left <= $low) {
                return [$today->modify('-1 day'), '']; // low now: restock
            }
            if ((float) $check['per_week'] > 0) {
                $counted = $date($check['counted_on'] ?? $today->format('Y-m-d'));
                $days = (int) floor(max(0, (float) $check['quantity'] - $low) / ((float) $check['per_week'] / 7));
                return [$counted->modify("+$days days"), ''];
            }
            return [null, ''];
        case 'level':
            $level = (int) ($check['quantity'] ?? 0);
            return match (true) {
                $level >= 100 => [$today->modify('-1 day'), ''],
                $level >= 75 => [$today->modify('+14 days'), ''],
                default => [$check['interval_days'] && $last ? $last->modify('+' . (int) $check['interval_days'] . ' days') : null, ''],
            };
        case 'kit':
            $due = $check['due_date'] ? $date($check['due_date']) : ($check['interval_days'] && $last ? $last->modify('+' . (int) $check['interval_days'] . ' days') : null);
            $expiries = array_filter(array_column(kit_items($check), 'expiry'));
            $first = $expiries ? min($expiries) : null;
            $note = $last ? '' : 'Mark it checked to start the schedule.';
            return [$first && (!$due || $first < $due) ? $first : $due, $note];
    }

    if ($check['due_date']) {
        return [$date($check['due_date']), ''];
    }

    if ($check['rule'] === 'autumn') {
        // The season runs from 1 September; the jab is due from then until spring.
        $seasonStart = $date(((int) $today->format('n') >= 9 ? $today->format('Y') : (int) $today->format('Y') - 1) . '-09-01');
        if ($last && $last >= $seasonStart) {
            return [$seasonStart->modify('+1 year'), ''];
        }
        if ((int) $today->format('n') >= 4 && (int) $today->format('n') < 9) {
            return [$date($today->format('Y') . '-09-01'), '']; // out of season: next autumn
        }
        return [$seasonStart, ''];
    }

    if ($check['rule'] === 'screening') {
        $p = SCREENING_PROGRAMMES[$check['kind']] ?? null;
        if (!$p) {
            return [null, ''];
        }
        if (!$user['date_of_birth']) {
            return [null, 'Add your date of birth to your health profile to work this out.'];
        }
        $dob = $date($user['date_of_birth']);
        $start = $dob->modify('+' . $p['from'] . ' years');
        $end = $dob->modify('+' . ($p['to'] + 1) . ' years'); // invitations stop at this birthday
        if ($last && $p['interval'] === null) {
            return [null, 'Done. This is a one-off check.'];
        }
        if (!$last && $start < $today->modify('-3 months')) {
            return $today >= $end
                ? [null, $p['after'] ?? 'You are past the age for routine invitations.']
                : [null, "Add when you were last screened to see when you're next due. If you've never been screened, contact your GP surgery."];
        }
        $next = $last ? $last->modify('+' . $p['interval'] . ' days') : $start;
        if ($next >= $end) {
            return [null, $p['after'] ?? 'You are past the age for routine invitations.'];
        }
        return [max($next, $start), ''];
    }

    if ($check['interval_days']) {
        return $last ? [$last->modify('+' . (int) $check['interval_days'] . ' days'), ''] : [null, 'Add when you last had it to work out when it is next due.'];
    }
    return [null, $last ? '' : 'Set the date it is due.'];
}

/** 'overdue', 'soon' (within 30 days), 'later' or 'unknown'. */
function check_status(?DateTimeImmutable $due, DateTimeImmutable $today): string
{
    return match (true) {
        $due === null => 'unknown',
        $due < $today => 'overdue',
        $due <= $today->modify('+30 days') => 'soon',
        default => 'later',
    };
}

/** Does this check keep a results log (blood tests, HbA1c...)? */
function check_has_results(array $check): bool
{
    return $check['area'] === 'bloods' || !empty(CARE_AREAS[$check['area']]['checks'][$check['kind']]['results']);
}

/** Certificate lengths in months (PPCs), or [] for anything else. */
function check_lengths(array $check): array
{
    return CARE_AREAS[$check['area']]['checks'][$check['kind']]['lengths'] ?? [];
}

function check_results(int $checkId, int $limit = 50): array
{
    return q('SELECT * FROM check_results WHERE check_id = ? ORDER BY taken_on DESC, id DESC LIMIT ' . $limit, [$checkId])->fetchAll();
}

/** Stock left now: the last count, less what's been used since at the weekly rate. */
function stock_left(array $check, DateTimeImmutable $today): float
{
    $qty = (float) $check['quantity'];
    if ((float) $check['per_week'] > 0 && $check['counted_on']) {
        $days = (new DateTimeImmutable($check['counted_on']))->diff($today)->days;
        $qty -= (float) $check['per_week'] / 7 * $days;
    }
    return max(0, $qty);
}

/**
 * Kit contents: one per line, "Name" or "Name | YYYY-MM-DD" (or YYYY-MM, meaning the end of that month).
 * @return array<int, array{name: string, expiry: ?DateTimeImmutable}>
 */
function kit_items(array $check): array
{
    $out = [];
    foreach (preg_split('/\R/', (string) $check['items']) as $line) {
        [$name, $exp] = array_map('trim', explode('|', $line, 2) + [1 => '']);
        if ($name === '') {
            continue;
        }
        $expiry = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
            $expiry = parse_date($exp);
        } elseif (preg_match('/^(\d{4})-(\d{2})$/', $exp, $m) && checkdate((int) $m[2], 1, (int) $m[1])) {
            $expiry = (new DateTimeImmutable("$exp-01", local_today()->getTimezone()))->modify('last day of this month');
        }
        $out[] = ['name' => $name, 'expiry' => $expiry, 'has_expiry' => $exp !== '' || str_contains($line, '|')];
    }
    return $out;
}

/** The main line for a check: due, expiry, stock or fill level, in words. */
function check_due_text(array $check, ?DateTimeImmutable $due, DateTimeImmutable $today): string
{
    $track = $check['track'] ?? 'due';
    if ($track === 'stock' && $check['quantity'] !== null) {
        $left = stock_left($check, $today);
        $unit = $check['unit'] ?: 'left';
        $count = ((float) $check['per_week'] > 0 ? 'About ' : '') . num(round($left)) . ($check['unit'] ? ' ' . $unit . ' left' : ' left');
        if ($left <= (float) ($check['low_at'] ?? 0)) {
            return 'Running low: ' . lcfirst($count) . '. Time to restock.';
        }
        return $count . ($due ? ' · restock by ' . $due->format($due->format('Y') === $today->format('Y') ? 'j M' : 'j M Y') : '');
    }
    if ($track === 'level') {
        $level = (int) ($check['quantity'] ?? 0);
        $text = FILL_LEVELS[$level] ?? "$level% full";
        return $level >= 100 ? "$text: close it and arrange collection" : ($level >= 75 ? "$text: arrange collection soon" : $text);
    }
    if ($due === null) {
        return '';
    }
    if ($track === 'kit') {
        // Say which item is behind the date, if it's an expiry rather than the regular check.
        $expiring = array_values(array_filter(kit_items($check), fn ($i) => $i['expiry'] && $i['expiry'] == $due));
        if ($expiring) {
            [, $text] = expiry_status($due->format('Y-m-d'), $today);
            return $expiring[0]['name'] . (count($expiring) > 1 ? ' and ' . (count($expiring) - 1) . ' more' : '') . ': ' . lcfirst($text);
        }
    }
    if ($track === 'expiry') {
        $days = (int) $today->diff($due)->format('%r%a');
        return match (true) {
            $days < 0 => 'Expired ' . $due->format('j M Y'),
            $days === 0 => 'Expires today',
            $days <= 30 => "Expires in $days day" . ($days === 1 ? '' : 's') . ' (' . $due->format('j M') . ')',
            default => 'Expires ' . $due->format('j M Y'),
        };
    }
    return due_label($due, $today);
}

function due_label(?DateTimeImmutable $due, DateTimeImmutable $today): string
{
    if ($due === null) {
        return '';
    }
    $days = (int) $today->diff($due)->format('%r%a');
    return match (true) {
        $days < -1 => 'Overdue since ' . $due->format('j M Y'),
        $days === -1 => 'Due yesterday',
        $days === 0 => 'Due today',
        $days === 1 => 'Due tomorrow',
        $days <= 30 => "Due in $days days (" . $due->format('j M') . ')',
        default => 'Due ' . $due->format('j M Y'),
    };
}

/** "every year", "every 2 years", "every 13 weeks", ... */
function interval_label(int $days): string
{
    [$n, $unit] = interval_parts($days);
    return $n === '1' ? 'every ' . rtrim($unit, 's') : "every $n $unit";
}

/**
 * Checks across the given areas that are overdue or due within 30 days, soonest first.
 * @return array<int, array{check: array, due: DateTimeImmutable, status: string}>
 */
function checks_coming_up(array $user, array $areas, DateTimeImmutable $today): array
{
    $out = [];
    foreach (health_checks((int) $user['id']) as $c) {
        if (!in_array($c['area'], $areas, true)) {
            continue;
        }
        [$due] = check_next_due($c, $user, $today);
        $status = check_status($due, $today);
        if ($status === 'overdue' || $status === 'soon') {
            $out[] = ['check' => $c, 'due' => $due, 'status' => $status];
        }
    }
    usort($out, fn ($a, $b) => $a['due'] <=> $b['due']);
    return $out;
}

/** Check the add / change form. @return array{0: array, 1: string[]} */
function check_from_post(string $area, ?array $existing): array
{
    $errors = [];
    $label = trim(mb_substr(post('label'), 0, 100));
    if ($label === '' && !$existing) {
        $errors[] = 'Give it a name.';
    }
    $interval = null;
    if (post('interval_n') !== '') {
        $n = (int) post('interval_n');
        $interval = match (post('interval_unit')) {
            'weeks' => $n * 7,
            'months' => (int) round($n * 30.4375),
            'years' => (int) round($n * 365.25),
            default => $n,
        };
        if ($n < 1 || $interval > 7305) {
            $errors[] = 'Repeat after between 1 day and 20 years, or leave it empty for a one-off.';
        }
    }
    $last = post('last_done') === '' ? null : parse_date(post('last_done'));
    if (post('last_done') !== '' && !$last) {
        $errors[] = 'Enter the date it was last done.';
    } elseif ($last && $last > local_today()) {
        $errors[] = 'The date it was last done is in the future.';
    }
    $due = post('due_date') === '' ? null : parse_date(post('due_date'));
    if (post('due_date') !== '' && !$due) {
        $errors[] = 'Enter the ' . ((post('track') ?: ($existing['track'] ?? 'due')) === 'expiry' ? 'expiry' : 'due') . ' date as a date.';
    }
    $track = $existing['track'] ?? (isset(CHECK_TRACKS[post('track')]) ? post('track') : 'due');
    $number = function (string $field, string $what) use (&$errors): ?float {
        $v = post($field);
        if ($v === '') {
            return null;
        }
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 99999) {
            $errors[] = "$what must be a number.";
            return null;
        }
        return (float) $v;
    };
    $quantity = $track === 'stock' ? $number('quantity', 'How many you have') : ($track === 'level' ? (float) (isset(FILL_LEVELS[(int) post('quantity')]) ? (int) post('quantity') : 0) : null);
    $items = $track === 'kit' ? trim(mb_substr(str_replace("\r", '', (string) ($_POST['items'] ?? '')), 0, 5000)) : null;
    return [[
        'label'         => $label ?: ($existing['label'] ?? ''),
        'track'         => $track,
        'interval_days' => $existing && $existing['rule'] ? $existing['interval_days'] : $interval,
        'last_done'     => $last?->format('Y-m-d'),
        'due_date'      => $due?->format('Y-m-d'),
        'quantity'      => $quantity,
        'unit'          => $track === 'stock' ? (trim(mb_substr(post('unit'), 0, 20)) ?: null) : null,
        'low_at'        => $track === 'stock' ? $number('low_at', 'The restock level') : null,
        'per_week'      => $track === 'stock' ? $number('per_week', 'How many you use a week') : null,
        'counted_on'    => in_array($track, ['stock', 'level'], true) && $quantity !== null
            ? ($existing && (float) $existing['quantity'] === $quantity ? $existing['counted_on'] : local_today()->format('Y-m-d')) : null,
        'items'         => $items ?: null,
        'medication_id' => post('medication_id') !== '' && find_medication((int) post('medication_id'), (int) current_user()['id']) ? (int) post('medication_id') : null,
        'notes'         => trim(mb_substr(post('notes'), 0, 500)) ?: null,
        'remind'        => post('remind') === '1' ? 1 : 0,
    ], $errors];
}

/** Split a number of days back into the form's [number, unit]: 3653 -> ['10', 'years']. */
function interval_parts(?int $days): array
{
    return match (true) {
        !$days => ['', 'months'],
        $days >= 365 && abs($days - round($days / 365.25) * 365.25) <= 2 => [(string) round($days / 365.25), 'years'],
        $days % 7 === 0 && $days <= 91 => [(string) ($days / 7), 'weeks'],
        $days >= 28 && abs($days / 30.4375 - round($days / 30.4375)) < 0.05 => [(string) round($days / 30.4375), 'months'],
        $days % 7 === 0 => [(string) ($days / 7), 'weeks'],
        default => [(string) $days, 'days'],
    };
}

/** All-day calendar feed events on each due date, with an alert at 9am. */
function checks_ics_events(array $user, array $checks, string $host): string
{
    $ics = '';
    $today = new DateTimeImmutable('today', new DateTimeZone($user['timezone']));
    foreach ($checks as $c) {
        if (!$c['remind'] || $c['hidden']) {
            continue;
        }
        [$due] = check_next_due($c, $user, $today);
        if (!$due) {
            continue;
        }
        $title = match ($c['track'] ?? 'due') {
            'expiry' => preg_replace('/\s+expiry$/i', '', $c['label']) . ($due < $today ? ' has expired' : ' expires'),
            'stock'  => 'Restock: ' . $c['label'],
            'level'  => $c['label'] . ': arrange collection',
            'kit'    => 'Check: ' . $c['label'],
            default  => $c['label'] . ($due < $today ? ' (overdue)' : ' due'),
        };
        $ics .= ics_line('BEGIN:VEVENT')
            . ics_line('UID:check-' . $c['id'] . '-' . $due->format('Ymd') . '@' . $host)
            . ics_line('DTSTAMP:' . ics_utc($c['created_at']))
            . ics_line('DTSTART;VALUE=DATE:' . max($due, $today)->format('Ymd'))
            . ics_line('SUMMARY:' . ics_text($title))
            . ($c['tip'] ? ics_line('DESCRIPTION:' . ics_text($c['tip'])) : '')
            . ics_line('CATEGORIES:' . ics_text(care_area($c['area'])['title']))
            . ics_line('TRANSP:TRANSPARENT')
            . ics_line('BEGIN:VALARM')
            . ics_line('ACTION:DISPLAY')
            . ics_line('DESCRIPTION:' . ics_text($title))
            . ics_line('TRIGGER:PT9H')
            . ics_line('END:VALARM')
            . ics_line('END:VEVENT');
    }
    return $ics;
}
