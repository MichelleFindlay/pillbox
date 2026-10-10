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

/** Does this screening programme apply to the user, from their profile? */
function screening_applies(array $user, string $kind): bool
{
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
    return $p === null ? null : $p + ['interval' => null, 'rule' => null, 'tip' => null];
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
    q('INSERT INTO health_checks (user_id, area, kind, label, interval_days, rule, tip) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, $area, $kind, $p['label'], $p['interval'], $p['rule'], $p['tip']]);
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
    if (!$user['sex']) {
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
    if ($check['due_date']) {
        return [$date($check['due_date']), ''];
    }
    $last = $check['last_done'] ? $date($check['last_done']) : null;

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
        $errors[] = 'Enter the due date as a date.';
    }
    return [[
        'label'         => $label ?: ($existing['label'] ?? ''),
        'interval_days' => $existing && $existing['rule'] ? $existing['interval_days'] : $interval,
        'last_done'     => $last?->format('Y-m-d'),
        'due_date'      => $due?->format('Y-m-d'),
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
        $title = $c['label'] . ($due < $today ? ' (overdue)' : ' due');
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
