<?php
declare(strict_types=1);

/*
 * Mind: mood check-ins, coping toolkit, safety plan and crisis contacts, PHQ-9 and
 * GAD-7, journal and thought records, insights. Optionally behind a PIN, except the
 * crisis contacts, which are always one tap away (help.php).
 */

const MOODS = [1 => ['&#x1F61E;', 'Very low'], 2 => ['&#x1F641;', 'Low'], 3 => ['&#x1F610;', 'OK'], 4 => ['&#x1F642;', 'Good'], 5 => ['&#x1F604;', 'Very good']];
const LEVELS = [1 => 'Very low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very high'];
const MIND_TAGS = ['calm', 'content', 'hopeful', 'motivated', 'anxious', 'low', 'irritable', 'stressed', 'overwhelmed',
    'lonely', 'tired', 'restless', 'numb', 'angry'];

// The NHS / Stanley-Brown safety plan: [heading, prompt].
const SAFETY_PLAN_SECTIONS = [
    'warning_signs' => ['My warning signs', 'Thoughts, feelings, situations or behaviours that tell me a crisis may be coming.'],
    'coping'        => ['Things I can do on my own', 'To take my mind off things or help me feel calmer, without contacting anyone.'],
    'distraction'   => ['People and places that take my mind off things', 'Who I can be with, and where I can go, to be around others.'],
    'help'          => ['People I can ask for help', 'Family or friends I can talk to about how I feel. Add their numbers under Contacts.'],
    'professionals' => ['Professionals and services', 'My GP, therapist, crisis team or keyworker, and when to contact them.'],
    'safe_place'    => ['Making my surroundings safer', 'Steps that keep me safe, such as asking someone to look after my medicines.'],
    'reasons'       => ['What matters to me', 'Reasons for living, and the people and things worth staying safe for.'],
];

// UK crisis lines, added to each user's contacts (and editable) the first time they open Mind.
const DEFAULT_CRISIS_CONTACTS = [
    ['name' => 'Emergency: 999', 'phone' => '999', 'detail' => 'If your life is at risk now, or you have seriously harmed yourself. Or go to A&E.'],
    ['name' => 'NHS 111, option 2', 'phone' => '111', 'detail' => 'Urgent mental health help, 24 hours a day (England and Wales). In Scotland, call NHS 24 on 111.'],
    ['name' => 'Samaritans', 'phone' => '116 123', 'detail' => 'Free, any time, day or night, to talk about anything.'],
    ['name' => 'Shout', 'text_to' => '85258', 'text_body' => 'SHOUT', 'detail' => 'Free, confidential text support, 24 hours a day.'],
];

/* PHQ-9 and GAD-7 (Spitzer, Williams, Kroenke and colleagues): free to use, no permission needed. */
const QUESTIONNAIRES = [
    'phq9' => [
        'title' => 'PHQ-9', 'about' => 'Depression',
        'intro' => 'Over the last 2 weeks, how often have you been bothered by any of the following problems?',
        'items' => [
            'Little interest or pleasure in doing things',
            'Feeling down, depressed, or hopeless',
            'Trouble falling or staying asleep, or sleeping too much',
            'Feeling tired or having little energy',
            'Poor appetite or overeating',
            'Feeling bad about yourself, or that you are a failure or have let yourself or your family down',
            'Trouble concentrating on things, such as reading the newspaper or watching television',
            'Moving or speaking so slowly that other people could have noticed. Or the opposite: being so fidgety or restless that you have been moving around a lot more than usual',
            'Thoughts that you would be better off dead, or of hurting yourself in some way',
        ],
        'bands' => [4 => 'Minimal', 9 => 'Mild', 14 => 'Moderate', 19 => 'Moderately severe', 27 => 'Severe'],
        'max' => 27,
    ],
    'gad7' => [
        'title' => 'GAD-7', 'about' => 'Anxiety',
        'intro' => 'Over the last 2 weeks, how often have you been bothered by the following problems?',
        'items' => [
            'Feeling nervous, anxious or on edge',
            'Not being able to stop or control worrying',
            'Worrying too much about different things',
            'Trouble relaxing',
            'Being so restless that it is hard to sit still',
            'Becoming easily annoyed or irritable',
            'Feeling afraid as if something awful might happen',
        ],
        'bands' => [4 => 'Minimal', 9 => 'Mild', 14 => 'Moderate', 21 => 'Severe'],
        'max' => 21,
    ],
];
const QUESTIONNAIRE_OPTIONS = ['Not at all', 'Several days', 'More than half the days', 'Nearly every day'];
const QUESTIONNAIRE_DIFFICULTY = ['Not difficult at all', 'Somewhat difficult', 'Very difficult', 'Extremely difficult'];

const JOURNAL_PROMPTS = [
    'What is on your mind right now?',
    'What went well today, however small?',
    'What has been hard this week, and how did you get through it?',
    'What would you say to a friend who felt the way you do?',
    'What is one thing you are looking forward to?',
    'What do you need more of, and less of, at the moment?',
];

// Thought record (CBT): field => [label, help].
const THOUGHT_FIELDS = [
    'situation' => ['Situation', 'What happened? Where were you, who with, when?'],
    'thought'   => ['Automatic thought', 'What went through your mind?'],
    'emotion'   => ['Emotion', 'What did you feel, and how strongly (0 to 100)?'],
    'for'       => ['Evidence for', 'What makes the thought seem true?'],
    'against'   => ['Evidence against', "What doesn't fit with it? What would someone else say?"],
    'balanced'  => ['Balanced thought', 'A fairer, more realistic way to see it.'],
    'after'     => ['How I feel now', 'The emotion again, and how strongly (0 to 100).'],
];

function mind_enabled(array $user): bool
{
    // Someone with shared access only sees Mind if the owner included it.
    return (bool) $user['mind_enabled'] && (!isset($user['_acting']) || $user['_acting']['mind']);
}

/* ---------- PIN lock ---------- */

const MIND_UNLOCK_SECONDS = 600; // stays unlocked for 10 minutes after the last Mind page

function mind_unlocked(array $user): bool
{
    // The owner chose to share Mind with this person, so their PIN isn't asked for.
    if (isset($user['_acting'])) {
        return $user['_acting']['mind'];
    }
    return !$user['mind_pin_hash'] || (($_SESSION['mind_unlocked_at'] ?? 0) > time() - MIND_UNLOCK_SECONDS);
}

/** Send to the PIN page unless unlocked; each Mind page keeps it unlocked a little longer. */
function require_mind(array $user): void
{
    if (!mind_enabled($user)) {
        if (isset($user['_acting'])) {
            flash('error', 'Mind is private: it has not been shared with you.');
            redirect('index.php');
        }
        flash('success', 'The Mind tab is switched off. You can switch it on in your health profile.');
        redirect('account.php#health-profile');
    }
    if (!mind_unlocked($user)) {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        redirect('mind_unlock.php?return=' . urlencode(basename($_SERVER['SCRIPT_NAME']) . ($query !== '' ? '?' . $query : '')));
    }
    if ($user['mind_pin_hash']) {
        $_SESSION['mind_unlocked_at'] = time();
    }
}

/* ---------- crisis contacts and safety plan ---------- */

/** Add the default crisis lines once, the first time someone opens Mind or Help. */
function seed_crisis_contacts(int $userId): void
{
    if (q("SELECT 1 FROM mind_plan WHERE user_id = ? AND section = '_seeded'", [$userId])->fetch()) {
        return;
    }
    foreach (DEFAULT_CRISIS_CONTACTS as $i => $c) {
        q("INSERT INTO mind_contacts (user_id, kind, name, detail, phone, text_to, text_body, sort) VALUES (?, 'crisis', ?, ?, ?, ?, ?, ?)",
            [$userId, $c['name'], $c['detail'], $c['phone'] ?? null, $c['text_to'] ?? null, $c['text_body'] ?? null, $i]);
    }
    q("INSERT IGNORE INTO mind_plan (user_id, section, content) VALUES (?, '_seeded', '1')", [$userId]);
}

/** @return array<string, array> contacts grouped by kind: crisis, person, professional */
function mind_contacts(int $userId): array
{
    $out = ['crisis' => [], 'professional' => [], 'person' => []];
    foreach (q('SELECT * FROM mind_contacts WHERE user_id = ? ORDER BY sort, id', [$userId]) as $c) {
        $out[$c['kind']][] = $c;
    }
    return $out;
}

/** @return array<string, string> safety plan section => text */
function safety_plan(int $userId): array
{
    $plan = [];
    foreach (q("SELECT section, content FROM mind_plan WHERE user_id = ? AND section <> '_seeded'", [$userId]) as $r) {
        $plan[$r['section']] = $r['content'];
    }
    return $plan;
}

/** tel: / sms: links for a contact. */
function contact_links(array $c): string
{
    $out = '';
    if ($c['phone']) {
        $out .= '<a class="btn btn-small btn-primary" href="tel:' . h(preg_replace('/[^\d+]/', '', $c['phone'])) . '">Call ' . h($c['phone']) . '</a>';
    }
    if ($c['text_to']) {
        $out .= '<a class="btn btn-small btn-primary" href="sms:' . h(preg_replace('/[^\d+]/', '', $c['text_to'])) . ($c['text_body'] ? '?&body=' . rawurlencode($c['text_body']) : '') . '">Text '
            . ($c['text_body'] ? h($c['text_body']) . ' to ' : '') . h($c['text_to']) . '</a>';
    }
    return $out;
}

/* ---------- check-ins ---------- */

/** @return array{0: ?array, 1: string[]} */
function checkin_from_post(DateTimeImmutable $today): array
{
    $mood = (int) post('mood');
    if (!isset(MOODS[$mood])) {
        return [null, ['Choose how your mood is.']];
    }
    $level = fn (string $f) => isset(LEVELS[(int) post($f)]) ? (int) post($f) : null;
    $anxiety = post('anxiety') === '' ? null : max(0, min(10, (int) post('anxiety')));
    $tags = array_values(array_intersect(MIND_TAGS, (array) ($_POST['tags'] ?? [])));
    return [[
        'mood'       => $mood,
        'energy'     => $level('energy'),
        'motivation' => $level('motivation'),
        'anxiety'    => $anxiety,
        'tags'       => $tags ? implode(',', $tags) : null,
        'triggers'   => trim(mb_substr(post('triggers'), 0, 500)) ?: null,
        'note'       => trim(mb_substr(post('note'), 0, 5000)) ?: null,
    ], []];
}

/** Check-ins between two local dates, newest first. */
function mind_checkins(int $userId, string $from, string $to): array
{
    return q('SELECT * FROM mind_checkins WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY logged_at DESC', [$userId, $from, $to])->fetchAll();
}

/** @return array<string, array{mood: float, energy: ?float, motivation: ?float, anxiety: ?float, n: int}> per local date */
function mind_daily(array $checkins): array
{
    $days = [];
    foreach ($checkins as $c) {
        $days[$c['log_date']][] = $c;
    }
    $avg = function (array $rows, string $f): ?float {
        $v = array_filter(array_column($rows, $f), fn ($x) => $x !== null);
        return $v ? array_sum($v) / count($v) : null;
    };
    $out = [];
    foreach ($days as $date => $rows) {
        $out[$date] = ['mood' => $avg($rows, 'mood'), 'energy' => $avg($rows, 'energy'), 'motivation' => $avg($rows, 'motivation'),
            'anxiety' => $avg($rows, 'anxiety'), 'n' => count($rows)];
    }
    ksort($out);
    return $out;
}

/**
 * Patterns in the last 90 days: mood after short sleep, and on days with physical
 * activity ticked off. Only when there are enough days on both sides and a clear gap.
 * @return string[] gentle one-line observations
 */
function mind_insights(array $user, DateTimeImmutable $today): array
{
    $from = $today->modify('-89 days');
    $daily = mind_daily(mind_checkins((int) $user['id'], $from->format('Y-m-d'), $today->format('Y-m-d')));
    if (count($daily) < 10) {
        return [];
    }
    $out = [];
    $compare = function (array $groupA, array $groupB, string $field, float $minGap = 0.5): ?array {
        $a = array_filter(array_column($groupA, $field), fn ($x) => $x !== null);
        $b = array_filter(array_column($groupB, $field), fn ($x) => $x !== null);
        if (count($a) < 4 || count($b) < 4) {
            return null;
        }
        $ma = array_sum($a) / count($a);
        $mb = array_sum($b) / count($b);
        return abs($ma - $mb) >= $minGap ? [$ma, $mb, count($a), count($b)] : null;
    };

    // Sleep: the night ending on each day (Pillbox log or Apple Health).
    $sleep = [];
    foreach (q("SELECT value1, recorded_at FROM health_readings WHERE user_id = ? AND metric = 'sleep' AND recorded_at >= ?",
        [$user['id'], $from->modify('-1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]) as $r) {
        $sleep[local_time($r['recorded_at'], 'Y-m-d')] = (float) $r['value1'];
    }
    $short = $rested = [];
    foreach ($daily as $date => $d) {
        if (isset($sleep[$date]) && $sleep[$date] < 6) {
            $short[] = $d;
        } elseif (isset($sleep[$date])) {
            $rested[] = $d;
        }
    }
    if ($r = $compare($short, $rested, 'mood')) {
        $out[] = sprintf('Your mood has averaged %.1f on days after under 6 hours of sleep, and %.1f after 6 hours or more (%d and %d days).', ...$r);
    }
    if ($r = $compare($short, $rested, 'anxiety', 1.0)) {
        $out[] = sprintf('Anxiety has averaged %.1f out of 10 after under 6 hours of sleep, and %.1f after more (%d and %d days).', ...$r);
    }

    // Physical activity routine ticked off that day.
    $activeDays = q("SELECT DISTINCT l.log_date FROM care_logs l JOIN care_tasks t ON t.id = l.task_id
                     WHERE l.user_id = ? AND t.area = 'activity' AND l.log_date >= ?", [$user['id'], $from->format('Y-m-d')])->fetchAll(PDO::FETCH_COLUMN);
    if ($activeDays) {
        $active = $inactive = [];
        foreach ($daily as $date => $d) {
            if (in_array($date, $activeDays, true)) {
                $active[] = $d;
            } else {
                $inactive[] = $d;
            }
        }
        if ($r = $compare($active, $inactive, 'mood')) {
            $out[] = sprintf('Your mood has averaged %.1f on days you ticked off physical activity, and %.1f on other days (%d and %d days).', ...$r);
        }
        if ($r = $compare($active, $inactive, 'energy')) {
            $out[] = sprintf('Energy has averaged %.1f on active days and %.1f on other days (%d and %d days).', ...$r);
        }
    }
    return $out;
}

/* ---------- questionnaires ---------- */

function questionnaire_band(string $kind, int $score): string
{
    foreach (QUESTIONNAIRES[$kind]['bands'] as $max => $label) {
        if ($score <= $max) {
            return $label;
        }
    }
    return '';
}

/** Neutral daily check-in reminder for the calendar feed: the wording doesn't mention mood or mental health. */
function mind_ics_events(array $user, string $host): string
{
    if (!$user['mind_enabled'] || !$user['mind_reminder']) {
        return '';
    }
    $time = str_replace(':', '', substr($user['mind_reminder'], 0, 5));
    return ics_line('BEGIN:VEVENT')
        . ics_line('UID:checkin-' . $user['id'] . '@' . $host)
        . ics_line('DTSTAMP:' . gmdate('Ymd\THis\Z'))
        . ics_line('DTSTART:' . gmdate('Ymd') . 'T' . $time . '00')
        . ics_line('DURATION:PT5M')
        . ics_line('RRULE:FREQ=DAILY')
        . ics_line('SUMMARY:Pillbox check-in')
        . ics_line('TRANSP:TRANSPARENT')
        . ics_line('BEGIN:VALARM')
        . ics_line('ACTION:DISPLAY')
        . ics_line('DESCRIPTION:Pillbox check-in')
        . ics_line('TRIGGER:PT0M')
        . ics_line('END:VALARM')
        . ics_line('END:VEVENT');
}

/* ---------- round two: care team, self-care, activities, connections, plans ---------- */

const MIND_APPOINTMENT_KINDS = ['mental_health', 'psychiatrist'];
const SELFCARE_DEFAULTS = ['Eaten', 'Drunk water', 'Been outside', 'Moved', 'Showered or washed', 'Talked to someone'];
const HELPS_WITH = ['talk' => 'Talking', 'practical' => 'Practical help', 'distraction' => 'Distraction', 'company' => 'Company'];
const ACTIVITY_IDEAS = ['Short walk', 'Phone a friend', 'Cook something simple', 'Tidy one drawer', 'Read for 15 minutes', 'Listen to an album',
    'Sit in the garden or a park', 'Do a small job I have been putting off', 'Have a bath', 'Draw, write or make something'];

// Relapse prevention plan: stage => [heading, [field => prompt]].
const RELAPSE_PLAN = [
    'green' => ['When I am well', [
        'green_like' => 'What I am like when I am well',
        'green_keep' => 'What keeps me well, day to day and week to week',
    ]],
    'amber' => ['Early warning signs', [
        'amber_signs'  => 'Signs that things are slipping: what I notice, and what others notice',
        'amber_me'     => 'What I will do',
        'amber_others' => 'What I would like other people to do',
    ]],
    'red' => ['Crisis', [
        'red_signs'   => 'Signs that I am in crisis',
        'red_do'      => 'What to do, and who to contact',
        'red_helps'   => 'What helps, and what does not, when I am this unwell',
    ]],
];

// Advance statement: section => [heading, prompt].
const ADVANCE_STATEMENT = [
    'adv_prefer'     => ['Treatment and support I prefer', 'And why: what has worked for me before.'],
    'adv_avoid'      => ['Treatment I would rather avoid', 'And why: what has not worked, or what I found distressing.'],
    'adv_meds'       => ['Medication', 'What has helped, side effects I have had, anything I am allergic to.'],
    'adv_hospital'   => ['If I need to go to hospital', 'Where I would prefer, what to bring, what helps me on a ward.'],
    'adv_people'     => ['People to tell', 'Who to contact, and anyone who should not be contacted.'],
    'adv_dependants' => ['Children, dependants and pets', 'Who will look after them.'],
    'adv_practical'  => ['Practical things', 'Home, keys, bills, work, benefits.'],
    'adv_needs'      => ['My needs', 'Cultural, religious, dietary, communication or accessibility needs.'],
    'adv_other'      => ['Anything else', ''],
    'adv_shared'     => ['Who has a copy', 'For example: my GP, care coordinator, nearest relative.'],
];

/** The self-care checklist items. */
function selfcare_items(array $user): array
{
    $items = $user['mind_selfcare'] === null ? SELFCARE_DEFAULTS : array_filter(array_map('trim', explode("\n", (string) $user['mind_selfcare'])));
    return array_values(array_unique(array_map(fn ($i) => mb_substr($i, 0, 60), $items)));
}

/** Plan sections (relapse plan, advance statement) share the mind_plan table with the safety plan. */
function plan_text(int $userId, array $keys): array
{
    $in = implode(',', array_fill(0, count($keys), '?'));
    $out = [];
    foreach (q("SELECT section, content FROM mind_plan WHERE user_id = ? AND section IN ($in)", [$userId, ...$keys]) as $r) {
        $out[$r['section']] = $r['content'];
    }
    return $out;
}

function save_plan_text(int $userId, array $keys, array $posted): void
{
    foreach ($keys as $key) {
        $text = trim(mb_substr((string) ($posted[$key] ?? ''), 0, 5000));
        if ($text === '') {
            q('DELETE FROM mind_plan WHERE user_id = ? AND section = ?', [$userId, $key]);
        } else {
            q('INSERT INTO mind_plan (user_id, section, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)', [$userId, $key, $text]);
        }
    }
}

function mind_notes(int $userId, string $kind, ?bool $done = null): array
{
    return q('SELECT n.*, a.starts_at, a.kind AS appointment_kind, a.title AS appointment_title FROM mind_notes n
              LEFT JOIN appointments a ON a.id = n.appointment_id
              WHERE n.user_id = ? AND n.kind = ?' . ($done === null ? '' : ' AND n.done = ' . (int) $done) . ' ORDER BY n.done, COALESCE(n.due_date, \'9999-12-31\'), n.id DESC',
        [$userId, $kind])->fetchAll();
}

/** People the user wanted reminding to contact, who are now due. */
function reach_out_due(int $userId, DateTimeImmutable $today): array
{
    $out = [];
    foreach (q("SELECT * FROM mind_contacts WHERE user_id = ? AND reach_out_days IS NOT NULL ORDER BY name", [$userId]) as $c) {
        $since = $c['last_contact'] ? (new DateTimeImmutable($c['last_contact']))->diff($today)->days : null;
        if ($since === null || $since >= (int) $c['reach_out_days']) {
            $out[] = $c + ['since' => $since];
        }
    }
    return $out;
}

/** Mood has been low (daily average 2 or under) on each of the last 3 days with a check-in in the last 5. */
function low_spell(int $userId, DateTimeImmutable $today): bool
{
    $daily = mind_daily(mind_checkins($userId, $today->modify('-4 days')->format('Y-m-d'), $today->format('Y-m-d')));
    $last = array_slice($daily, -3, 3, true);
    return count($last) === 3 && max(array_column($last, 'mood')) <= 2;
}

/** Active symptoms, and their ratings by check-in id. */
function mind_symptoms(int $userId, bool $activeOnly = true): array
{
    return q('SELECT * FROM mind_symptoms WHERE user_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name', [$userId])->fetchAll();
}

/** @return array<int, array<int, int>> checkin_id => [symptom_id => severity] */
function symptom_ratings(array $checkinIds): array
{
    if (!$checkinIds) {
        return [];
    }
    $out = [];
    $in = implode(',', array_fill(0, count($checkinIds), '?'));
    foreach (q("SELECT * FROM mind_symptom_logs WHERE checkin_id IN ($in)", array_values($checkinIds)) as $r) {
        $out[$r['checkin_id']][$r['symptom_id']] = (int) $r['severity'];
    }
    return $out;
}

/** Medicines marked as mental health medicines, with their latest change. */
function mind_medications(int $userId): array
{
    return q("SELECT m.*, (SELECT CONCAT(c.effective_date, '|', c.summary) FROM medication_changes c WHERE c.medication_id = m.id ORDER BY c.effective_date DESC, c.id DESC LIMIT 1) AS last_change
              FROM medications m WHERE m.user_id = ? AND m.mental_health = 1 AND m.is_active = 1 ORDER BY m.name", [$userId])->fetchAll();
}

function med_notes(int $userId, ?int $medicationId = null, ?string $sinceUtc = null): array
{
    return q('SELECT n.*, m.name FROM med_notes n JOIN medications m ON m.id = n.medication_id WHERE n.user_id = ?'
        . ($medicationId ? ' AND n.medication_id = ' . (int) $medicationId : '') . ($sinceUtc ? ' AND n.created_at >= ?' : '') . ' ORDER BY n.created_at DESC',
        $sinceUtc ? [$userId, $sinceUtc] : [$userId])->fetchAll();
}
