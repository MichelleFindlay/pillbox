<?php
declare(strict_types=1);

const APPOINTMENT_TYPES = [
    'gp'            => 'GP / doctor',
    'specialist'    => 'Specialist / consultant',
    'hospital'      => 'Hospital',
    'nurse'         => 'Nurse',
    'dentist'       => 'Dentist',
    'optician'      => 'Optician / eye test',
    'hearing'       => 'Hearing / audiology',
    'blood_test'    => 'Blood test / tests',
    'scan'          => 'Scan / X-ray',
    'antenatal'     => 'Antenatal / maternity',
    'gic'           => 'Gender identity clinic',
    'endocrinology' => 'Endocrinology',
    'surgery'       => 'Surgery / surgical consultation',
    'physio'        => 'Physiotherapy',
    'podiatry'      => 'Podiatry / foot care',
    'mental_health' => 'Mental health / therapy',
    'psychiatrist'  => 'Psychiatrist',
    'pharmacy'      => 'Pharmacy / medication review',
    'vaccination'   => 'Vaccination',
    'allergy'       => 'Allergy clinic',
    'other'         => 'Other',
];

const APPOINTMENT_DURATIONS = [10 => '10 minutes', 15 => '15 minutes', 20 => '20 minutes', 30 => '30 minutes', 45 => '45 minutes',
    60 => '1 hour', 90 => '1½ hours', 120 => '2 hours', 180 => '3 hours', 240 => '4 hours'];

const APPOINTMENT_REMINDERS = [0 => 'No reminder', 30 => '30 minutes before', 60 => '1 hour before', 120 => '2 hours before',
    1440 => '1 day before', 2880 => '2 days before', 10080 => '1 week before'];

function appointment_type_label(string $kind): string
{
    return APPOINTMENT_TYPES[$kind] ?? 'Appointment';
}

/** Headline for an appointment: its own title, or the type. */
function appointment_title(array $a): string
{
    return $a['title'] !== null && $a['title'] !== '' ? $a['title'] : appointment_type_label($a['kind']);
}

function find_appointment(int $id, int $userId): ?array
{
    return q('SELECT * FROM appointments WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
}

/** Appointments starting between two UTC instants, soonest first. */
function appointments_between(int $userId, string $fromUtc, string $toUtc): array
{
    return q('SELECT * FROM appointments WHERE user_id = ? AND starts_at >= ? AND starts_at < ? ORDER BY starts_at',
        [$userId, $fromUtc, $toUtc])->fetchAll();
}

/** The private calendar feed URL for a token. */
function calendar_feed_url(string $token): string
{
    return absolute_url('calendar.php?token=' . $token);
}

/* ---------- iCalendar (RFC 5545) output ---------- */

function ics_text(string $value): string
{
    return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', '\\n'], $value);
}

/** Fold lines to at most 75 octets (continuations start with a space), without splitting UTF-8 characters. */
function ics_line(string $line): string
{
    $out = '';
    $max = 75;
    while (strlen($line) > $max) {
        $cut = $max;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $max = 74; // the leading space counts towards the limit
    }
    return $out . $line . "\r\n";
}

function ics_utc(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Ymd\THis\Z');
}

/** $extraEvents: more VEVENTs to include, already formatted (e.g. care routine reminders). */
function appointments_ics(array $user, array $appointments, string $host, string $extraEvents = ''): string
{
    $ics = ics_line('BEGIN:VCALENDAR')
        . ics_line('VERSION:2.0')
        . ics_line('PRODID:-//Pillbox//Appointments//EN')
        . ics_line('CALSCALE:GREGORIAN')
        . ics_line('METHOD:PUBLISH')
        . ics_line('X-WR-CALNAME:' . ics_text('Medical appointments (' . $user['display_name'] . ')'))
        . ics_line('X-WR-TIMEZONE:' . ics_text($user['timezone']))
        . ics_line('REFRESH-INTERVAL;VALUE=DURATION:PT1H')
        . ics_line('X-PUBLISHED-TTL:PT1H');

    foreach ($appointments as $a) {
        $end = (new DateTimeImmutable($a['starts_at'], new DateTimeZone('UTC')))->modify('+' . (int) $a['duration_minutes'] . ' minutes');
        $description = [];
        if ($a['with_whom']) {
            $description[] = 'With: ' . $a['with_whom'];
        }
        $description[] = 'Type: ' . appointment_type_label($a['kind']);
        if ($user['calendar_include_notes'] && $a['notes']) {
            $description[] = '';
            $description[] = $a['notes'];
        }
        $ics .= ics_line('BEGIN:VEVENT')
            . ics_line('UID:appointment-' . $a['id'] . '@' . $host)
            . ics_line('DTSTAMP:' . ics_utc($a['updated_at']))
            . ics_line('LAST-MODIFIED:' . ics_utc($a['updated_at']))
            . ics_line('DTSTART:' . ics_utc($a['starts_at']))
            . ics_line('DTEND:' . $end->format('Ymd\THis\Z'))
            . ics_line('SUMMARY:' . ics_text(appointment_title($a) . ($a['with_whom'] ? ' – ' . $a['with_whom'] : '')))
            . ($a['location'] ? ics_line('LOCATION:' . ics_text($a['location'])) : '')
            . ics_line('DESCRIPTION:' . ics_text(implode("\n", $description)))
            . ics_line('CATEGORIES:' . ics_text(appointment_type_label($a['kind'])))
            . ics_line('TRANSP:OPAQUE');
        if ((int) $a['remind_minutes'] > 0) {
            $ics .= ics_line('BEGIN:VALARM')
                . ics_line('ACTION:DISPLAY')
                . ics_line('DESCRIPTION:' . ics_text(appointment_title($a)))
                . ics_line('TRIGGER:-PT' . (int) $a['remind_minutes'] . 'M')
                . ics_line('END:VALARM');
        }
        $ics .= ics_line('END:VEVENT');
    }
    return $ics . $extraEvents . ics_line('END:VCALENDAR');
}

/* ---------- Preparing for an appointment ---------- */

function appointment_questions(int $appointmentId): array
{
    return q('SELECT * FROM appointment_questions WHERE appointment_id = ? ORDER BY done, id', [$appointmentId])->fetchAll();
}

/** Question counts per appointment id: [id => ['total' => n, 'done' => n]]. */
function appointment_question_counts(array $appointmentIds): array
{
    if (!$appointmentIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($appointmentIds), '?'));
    $counts = [];
    foreach (q("SELECT appointment_id, COUNT(*) AS total, SUM(done) AS done FROM appointment_questions WHERE appointment_id IN ($in) GROUP BY appointment_id", $appointmentIds) as $r) {
        $counts[$r['appointment_id']] = ['total' => (int) $r['total'], 'done' => (int) $r['done']];
    }
    return $counts;
}

/**
 * Things worth raising, from what Pillbox already knows since the previous appointment
 * (or the last 30 days): side effects, missed doses, low stock, dose changes, readings.
 */
function appointment_suggestions(array $user, array $appointment): array
{
    $uid = (int) $user['id'];
    // The last appointment that has already happened (before this one).
    $previous = q('SELECT MAX(starts_at) FROM appointments WHERE user_id = ? AND starts_at < ? AND id <> ?',
        [$uid, min($appointment['starts_at'], utc_now()), $appointment['id']])->fetchColumn();
    // Since the previous appointment (at most 90 days back), or the last 30 days if there wasn't one.
    $sinceUtc = $previous
        ? max((string) $previous, gmdate('Y-m-d H:i:s', strtotime('-90 days')))
        : gmdate('Y-m-d H:i:s', strtotime('-30 days'));
    $sinceLabel = local_time($sinceUtc, 'j M');
    $until = min($appointment['starts_at'], utc_now());
    $out = [];

    // Mental health appointments: the running question list, and how things have been in Mind.
    if (in_array($appointment['kind'], MIND_APPOINTMENT_KINDS, true) && mind_visible($user)) { // not while Mind is PIN-locked
        foreach (mind_notes($uid, 'question', false) as $n) {
            $out[] = $n['text'];
        }
        $fromDay = local_time($sinceUtc, 'Y-m-d');
        $daily = mind_daily(mind_checkins($uid, $fromDay, local_time($until, 'Y-m-d')));
        if (count($daily) >= 3) {
            $avg = fn (string $f) => ($v = array_filter(array_column($daily, $f), fn ($x) => $x !== null)) ? array_sum($v) / count($v) : null;
            $out[] = sprintf('Since %s my mood has averaged %.1f out of 5 over %d days', $sinceLabel, $avg('mood'), count($daily))
                . ($avg('anxiety') !== null ? sprintf(', and anxiety %.1f out of 10.', $avg('anxiety')) : '.');
        }
        foreach (QUESTIONNAIRES as $kind => $qn) {
            $scores = q('SELECT score, taken_at FROM mind_questionnaires WHERE user_id = ? AND kind = ? AND taken_at >= ? ORDER BY taken_at', [$uid, $kind, $sinceUtc])->fetchAll();
            if ($scores) {
                $first = $scores[0];
                $last = (int) end($scores)['score'];
                $out[] = "My latest {$qn['title']} score is $last (" . mb_strtolower(questionnaire_band($kind, $last)) . ')'
                    . (count($scores) > 1 ? ', ' . ((int) $first['score'] < $last ? 'up' : ((int) $first['score'] > $last ? 'down' : 'no change')) . ' from ' . (int) $first['score']
                        . ' on ' . local_time($first['taken_at'], 'j M') . '.' : '.');
            }
        }
        foreach (array_reverse(med_notes($uid, null, $sinceUtc)) as $n) {
            $out[] = ($n['kind'] === 'side_effect' ? 'Side effect from ' : 'Since starting or changing ') . $n['name'] . ': ' . $n['note']
                . ($n['severity'] !== null ? ' (' . $n['severity'] . '/10)' : '') . ' (' . local_time($n['created_at'], 'j M') . ').';
        }
    }

    // Symptoms and side effects logged.
    foreach (readings_q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'symptom' AND recorded_at >= ? AND recorded_at <= ? ORDER BY recorded_at",
        [$uid, $sinceUtc, $until]) as $r) {
        $out[] = 'Symptom on ' . local_time($r['recorded_at'], 'j M') . ': ' . ($r['notes'] ?: 'no description')
            . ($r['value1'] !== null ? ' (severity ' . num($r['value1']) . '/10)' : '');
    }

    // Doses missed.
    $tz = user_tz($user);
    $fromDay = (new DateTimeImmutable($sinceUtc, new DateTimeZone('UTC')))->setTimezone($tz);
    $toDay = (new DateTimeImmutable($until, new DateTimeZone('UTC')))->setTimezone($tz);
    foreach (adherence(dose_slots($user, $fromDay, $toDay)) as $a) {
        if ($a['missed'] + $a['skipped'] >= 2 && $a['pct'] !== null && $a['pct'] < 90) {
            $out[] = "Since $sinceLabel I've missed or skipped " . ($a['missed'] + $a['skipped']) . " doses of {$a['name']} ({$a['pct']}% taken) - is there anything that would help?";
        }
    }

    // Heavy use of as-needed medicines.
    foreach (q('SELECT m.name, COUNT(*) AS n FROM dose_logs l JOIN medications m ON m.id = l.medication_id
                WHERE l.user_id = ? AND l.schedule_id IS NULL AND l.taken_at >= ? AND l.taken_at <= ?' . private_med_filter() . ' GROUP BY m.id HAVING n >= 5',
        [$uid, $sinceUtc, $until]) as $r) {
        $out[] = "I've needed {$r['name']} {$r['n']} times since $sinceLabel.";
    }

    // Recent dose changes.
    foreach (q("SELECT c.*, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id
                WHERE c.user_id = ? AND c.effective_date >= ? AND c.kind NOT IN ('note')" . private_med_filter() . " ORDER BY c.effective_date",
        [$uid, local_time($sinceUtc, 'Y-m-d')]) as $c) {
        $out[] = "How the change to {$c['name']} is going: " . lcfirst($c['summary']) . ' (' . date('j M', strtotime($c['effective_date'])) . ').';
    }

    // Repeat prescriptions running low.
    $meds = user_medications($uid);
    $schedules = schedules_by_medication(array_column($meds, 'id'), local_today()->format('Y-m-d'));
    foreach ($meds as $m) {
        $days = stock_days_left($m, $schedules[$m['id']] ?? []);
        if ($days !== null && $days < 21) {
            $out[] = "Repeat prescription for {$m['name']} - about " . (int) floor($days) . ' days left.';
        }
    }

    // Blood pressure trend.
    $bp = readings_q("SELECT AVG(value1) AS sys, AVG(value2) AS dia, COUNT(*) AS n FROM health_readings
             WHERE user_id = ? AND metric = 'blood_pressure' AND recorded_at >= ? AND recorded_at <= ?", [$uid, $sinceUtc, $until])->fetch();
    if ($bp && (int) $bp['n'] >= 3) { // (no row at all when readings aren't shared)
        $out[] = 'My blood pressure averaged ' . round((float) $bp['sys']) . '/' . round((float) $bp['dia']) . " mmHg over {$bp['n']} readings since $sinceLabel.";
    }

    return array_values(array_unique($out));
}
