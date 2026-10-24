<?php
declare(strict_types=1);

/*
 * Pregnancy (a Care category with its own page): due date and gestation, the NHS antenatal
 * timeline (appointments, scans, vaccines, forms), what was checked at each appointment,
 * screening results, changes in the baby's movements, and when to call the maternity unit.
 * It sits alongside the maternity unit's own notes; it never interprets results, only points
 * to the midwife when something needs checking.
 */

// The default NHS timeline, by week of pregnancy: [from week, to week, label, kind, first babies only, what it's for].
// Kinds: appointment, scan, vaccine, admin. 'result' items have space for results; 'optional' ones only apply sometimes.
const PREGNANCY_TIMELINE = [
    'notify'       => ['from' => 4, 'to' => 10, 'label' => 'Tell a midwife or GP you are pregnant', 'kind' => 'admin',
        'tip' => 'Self-refer to your local maternity service, ideally before 10 weeks. Sickle cell and thalassaemia screening should be offered before 10 weeks.'],
    'matex'        => ['from' => 4, 'to' => 16, 'label' => 'Maternity exemption certificate (MatEx)', 'kind' => 'admin',
        'tip' => 'Free NHS prescriptions and dental care until 12 months after the birth. Ask your midwife or GP for form FW8.'],
    'booking'      => ['from' => 8, 'to' => 12, 'label' => 'Booking appointment', 'kind' => 'appointment', 'result' => true,
        'tip' => 'About an hour with your midwife: your health and history, blood pressure, urine, height and weight, blood tests, and the screening on offer.'],
    'dating_scan'  => ['from' => 11, 'to' => 14, 'label' => 'Dating scan and screening', 'kind' => 'scan', 'result' => true,
        'tip' => 'Confirms your due date (update it here afterwards) and offers combined screening for Down\'s, Edwards\' and Patau\'s syndromes.'],
    'week16'       => ['from' => 16, 'to' => 16, 'label' => '16-week check', 'kind' => 'appointment',
        'tip' => 'Your screening results, blood pressure and urine.'],
    'whooping'     => ['from' => 16, 'to' => 32, 'label' => 'Whooping cough vaccine', 'kind' => 'vaccine',
        'tip' => 'Offered from 16 weeks, ideally around 20 weeks, to protect your baby in their first weeks.'],
    'anomaly_scan' => ['from' => 18, 'to' => 21, 'label' => 'Anomaly scan (20-week scan)', 'kind' => 'scan', 'result' => true,
        'tip' => "A detailed check of your baby's development."],
    'matb1'        => ['from' => 20, 'to' => 26, 'label' => 'MATB1 certificate', 'kind' => 'admin',
        'tip' => 'From your midwife or GP from 20 weeks. Your employer needs it for Statutory Maternity Pay (or Jobcentre Plus for Maternity Allowance).'],
    'leave_notice' => ['from' => 20, 'to' => 25, 'label' => 'Tell your employer when your maternity leave starts', 'kind' => 'admin',
        'tip' => 'By the end of the 15th week before your due week (about 25 weeks), with your MATB1.'],
    'week25'       => ['from' => 25, 'to' => 25, 'label' => '25-week check', 'kind' => 'appointment', 'first' => true,
        'tip' => 'Bump measurement, blood pressure and urine.'],
    'ogtt'         => ['from' => 24, 'to' => 28, 'label' => 'Gestational diabetes test (OGTT), if offered', 'kind' => 'appointment', 'result' => true, 'optional' => true,
        'tip' => 'A glucose tolerance test, offered if you are more likely to get diabetes in pregnancy (for example, a high BMI, a previous large baby, or a family history).'],
    'week28'       => ['from' => 28, 'to' => 28, 'label' => '28-week check and blood tests', 'kind' => 'appointment', 'result' => true,
        'tip' => 'Blood tests for anaemia and antibodies, and anti-D if you are Rhesus negative.'],
    'rsv'          => ['from' => 28, 'to' => 36, 'label' => 'RSV vaccine', 'kind' => 'vaccine',
        'tip' => 'Offered from 28 weeks, to protect your baby from RSV (a cause of bronchiolitis).'],
    'week31'       => ['from' => 31, 'to' => 31, 'label' => '31-week check', 'kind' => 'appointment', 'first' => true,
        'tip' => 'Results of your 28-week tests, bump measurement, blood pressure and urine.'],
    'birth_plan'   => ['from' => 30, 'to' => 36, 'label' => 'Birth plan', 'kind' => 'admin',
        'tip' => 'Write down your preferences and talk them through with your midwife.'],
    'week34'       => ['from' => 34, 'to' => 34, 'label' => '34-week check', 'kind' => 'appointment', 'result' => true,
        'tip' => 'Preparing for labour and birth. Some people have blood tests or a second anti-D dose.'],
    'hospital_bag' => ['from' => 34, 'to' => 36, 'label' => 'Pack your hospital bag', 'kind' => 'admin',
        'tip' => 'Have it ready from about 36 weeks, in case your baby comes early.'],
    'week36'       => ['from' => 36, 'to' => 36, 'label' => '36-week check', 'kind' => 'appointment',
        'tip' => "Your baby's position (head down or breech), birth plans and feeding."],
    'week38'       => ['from' => 38, 'to' => 38, 'label' => '38-week check', 'kind' => 'appointment',
        'tip' => 'Your options if you go past your due date.'],
    'week40'       => ['from' => 40, 'to' => 40, 'label' => '40-week check', 'kind' => 'appointment', 'first' => true,
        'tip' => 'What happens if you go past your due date.'],
    'week41'       => ['from' => 41, 'to' => 41, 'label' => '41-week check', 'kind' => 'appointment',
        'tip' => 'A membrane sweep, and talking about induction of labour.'],
    'week42'       => ['from' => 42, 'to' => 42, 'label' => 'Extra monitoring', 'kind' => 'appointment', 'optional' => true,
        'tip' => "If you choose not to be induced: regular checks of your baby's heartbeat and movements."],
    'flu'          => ['from' => 4, 'to' => 42, 'label' => 'Flu vaccine', 'kind' => 'vaccine', 'optional' => true,
        'tip' => 'Recommended at any stage of pregnancy during flu season (autumn and winter). Free from your GP or a pharmacy.'],
];

const PREGNANCY_KIND_ICONS = ['appointment' => '&#x1F469;&#x200D;&#x2695;&#xFE0F;', 'scan' => '&#x1F5A5;&#xFE0F;', 'vaccine' => '&#x1F489;', 'admin' => '&#x1F4DD;'];

const URINE_RESULTS = ['clear' => 'Clear', 'protein' => 'Protein', 'glucose' => 'Glucose', 'both' => 'Protein and glucose', 'not_done' => 'Not tested'];
const BABY_POSITIONS = ['head_down' => 'Head down', 'breech' => 'Bottom or feet first (breech)', 'transverse' => 'Lying across (transverse)', 'unsure' => 'Not sure yet'];

// When to call the maternity unit straight away (NHS advice).
const PREGNANCY_RED_FLAGS = [
    "Your baby is moving less than usual, or the pattern of movements has changed",
    'Bleeding from your vagina',
    'A severe headache, problems with your vision (blurring, flashing lights) or sudden swelling of your face, hands or feet',
    'Your waters break, or fluid is leaking',
    'Severe or constant tummy pain',
    'A high temperature, or feeling hot and shivery',
    'Itching, especially on your hands and feet, later in pregnancy',
];

/** The user's current pregnancy, if any. */
function active_pregnancy(int $userId): ?array
{
    return q("SELECT * FROM pregnancies WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$userId])->fetch() ?: null;
}

/** The first day of the pregnancy as counted by the NHS (the last period): 280 days before the due date. */
function pregnancy_start(array $p): DateTimeImmutable
{
    return (new DateTimeImmutable($p['due_date']))->modify('-280 days');
}

/** [weeks, days] pregnant on $day. */
function gestation(array $p, DateTimeImmutable $day): array
{
    $days = (int) pregnancy_start($p)->diff(new DateTimeImmutable($day->format('Y-m-d')))->format('%r%a');
    $days = max(0, $days);
    return [intdiv($days, 7), $days % 7];
}

/** "24 weeks 3 days" (or "24+3" with $short). */
function gestation_label(array $p, DateTimeImmutable $day, bool $short = false): string
{
    [$w, $d] = gestation($p, $day);
    return $short ? "$w+$d" : $w . ' week' . ($w === 1 ? '' : 's') . ($d ? " $d day" . ($d === 1 ? '' : 's') : '');
}

function trimester(array $p, DateTimeImmutable $day): int
{
    [$w] = gestation($p, $day);
    return $w < 13 ? 1 : ($w < 27 ? 2 : 3);
}

/**
 * The timeline for this pregnancy: each item with its dates and state, in date order.
 * State: done | now (in its weeks) | upcoming | past (its weeks have gone and it wasn't ticked).
 */
function pregnancy_timeline(array $p, DateTimeImmutable $today): array
{
    $start = pregnancy_start($p);
    $done = [];
    foreach (q('SELECT * FROM pregnancy_items WHERE pregnancy_id = ?', [$p['id']]) as $row) {
        $done[$row['item_key']] = $row;
    }
    $ymd = $today->format('Y-m-d');
    $items = [];
    foreach (PREGNANCY_TIMELINE as $key => $item) {
        if (!empty($item['first']) && !$p['first_baby']) {
            continue;
        }
        $from = $start->modify('+' . ($item['from'] * 7) . ' days');
        $to = $start->modify('+' . ($item['to'] * 7 + 6) . ' days');
        $record = $done[$key] ?? null;
        $state = $record && $record['done_on'] ? 'done'
            : ($ymd > $to->format('Y-m-d') ? 'past' : ($ymd >= $from->format('Y-m-d') ? 'now' : 'upcoming'));
        $items[$key] = $item + ['key' => $key, 'from_date' => $from, 'to_date' => $to, 'state' => $state, 'record' => $record];
    }
    uasort($items, fn ($a, $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
    return $items;
}

/** The next thing to do: something due now, or the soonest upcoming item (not optional ones). */
function pregnancy_next(array $timeline): ?array
{
    foreach (['now', 'upcoming'] as $state) {
        foreach ($timeline as $item) {
            if ($item['state'] === $state && empty($item['optional'])) {
                return $item;
            }
        }
    }
    return null;
}

/** "weeks 11 to 14" / "week 16" */
function pregnancy_weeks_label(array $item): string
{
    return $item['from'] === $item['to'] ? 'week ' . $item['from'] : 'weeks ' . $item['from'] . ' to ' . $item['to'];
}

/** Antenatal blood pressure at or over 140/90 is something the midwife should know about. */
function antenatal_bp_flag(?int $sys, ?int $dia): bool
{
    return ($sys !== null && $sys >= 140) || ($dia !== null && $dia >= 90);
}

/** Save a check-up's blood pressure as a reading too, so it shows on the Readings charts. */
function sync_antenatal_bp(int $userId, int $checkId, ?int $sys, ?int $dia, string $date): void
{
    if (!readings_writable()) {
        return; // someone with shared access but no permission to change readings: the check-up is saved, Readings left alone
    }
    $ext = sha1('antenatal-' . $checkId);
    q('DELETE FROM health_readings WHERE user_id = ? AND external_id = ?', [$userId, $ext]);
    if ($sys && $dia) {
        q("INSERT INTO health_readings (user_id, metric, value1, value2, notes, recorded_at, source, external_id) VALUES (?, 'blood_pressure', ?, ?, 'Antenatal check', ?, 'antenatal', ?)",
            [$userId, $sys, $dia, local_to_utc($date . ' 12:00') ?? ($date . ' 12:00:00'), $ext]);
    }
}
