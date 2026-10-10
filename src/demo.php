<?php
declare(strict_types=1);

/*
 * Demo mode (config 'demo' => ['enabled' => true]). Each visitor gets a throwaway
 * account for "Nell" Whitcombe, a fictional person (the same one as the ICE demo),
 * filled with sample data. It's deleted when they sign out, or after 'demo.hours'.
 * Sign-in details, email, sharing, sync and calendar links are locked in the demo.
 * Phone numbers are from Ofcom's drama range (07700 900xxx), so they ring no one.
 */

function demo_enabled(): bool
{
    return (bool) config('demo.enabled', false);
}

function is_demo(): bool
{
    return (bool) (current_user()['is_demo'] ?? false);
}

/** Stop something that would reach outside the demo (sign-in details, email, sync links). */
function demo_blocked(string $what = 'That'): void
{
    if (is_demo()) {
        flash('error', "$what is switched off in the demo.");
        redirect(safe_return(basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: ''), 'index.php'));
    }
}

/** Create a fresh demo account and return it. */
function create_demo_account(): array
{
    // Tidy up abandoned demos here too, for sites without the scheduled tasks.
    q('DELETE FROM users WHERE is_demo = 1 AND created_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600 * max(1, (int) config('demo.hours', 2)))]);
    return transaction(function () {
        q("INSERT INTO users (username, display_name, password_hash, auth_provider, timezone, is_demo, date_of_birth, sex, care_areas, enabled_metrics, onboarded_at)
           VALUES (?, 'Nell Whitcombe', NULL, 'local', 'Europe/London', 1, '1979-03-14', 'female', ?, ?, UTC_TIMESTAMP())",
            ['demo-' . bin2hex(random_bytes(6)), 'dental,eyes,sleep,activity,diabetes,womens,emergency,screening,vaccinations',
                'blood_glucose,blood_pressure,weight,sleep,mood,symptom']);
        $uid = (int) db()->lastInsertId();
        seed_demo_data($uid);
        return q('SELECT * FROM users WHERE id = ?', [$uid])->fetch();
    });
}

function seed_demo_data(int $uid): void
{
    $tz = new DateTimeZone('Europe/London');
    $today = new DateTimeImmutable('today', $tz);
    $utc = new DateTimeZone('UTC');
    $at = fn (DateTimeImmutable $day, string $time) => (new DateTimeImmutable($day->format('Y-m-d') . " $time", $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ymd = fn (int $days) => $today->modify("$days days")->format('Y-m-d');
    mt_srand($uid);

    // Medicines.
    $med = function (array $m, array $times) use ($uid): int {
        q('INSERT INTO medications (user_id, name, strength, form, dose_unit, colour, reason, instructions, prescriber, as_needed, prn_dose_amount, prn_max_daily,
                                    stock_count, low_stock_days, start_date, expiry_date, mental_health)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
            [$uid, $m['name'], $m['strength'], $m['form'], $m['unit'], $m['colour'], $m['reason'], $m['instructions'] ?? null, 'Dr Priya Anand',
                (int) !$times, $m['prn'] ?? null, $m['max'] ?? null, $m['stock'] ?? null, $m['low'] ?? 7, $m['start'], $m['expiry'] ?? null]);
        $id = (int) db()->lastInsertId();
        foreach ($times as [$time, $amount, $from, $to]) {
            q('INSERT INTO medication_schedules (medication_id, dose_time, dose_amount, days_mask, valid_from, valid_to) VALUES (?, ?, ?, 127, ?, ?)',
                [$id, "$time:00", $amount, $from, $to]);
        }
        return $id;
    };
    $lamo = $med(['name' => 'Lamotrigine', 'strength' => '100mg', 'form' => 'tablet', 'unit' => 'tablet', 'colour' => 'purple', 'reason' => 'Epilepsy (tonic-clonic)',
        'stock' => 40, 'low' => 10, 'start' => $ymd(-400)], [['08:00', 1.5, null, $ymd(-43)], ['20:00', 1.5, null, $ymd(-43)], ['08:00', 2, $ymd(-42), null], ['20:00', 2, $ymd(-42), null]]);
    $levo = $med(['name' => 'Levothyroxine', 'strength' => '50mcg', 'form' => 'tablet', 'unit' => 'tablet', 'colour' => 'teal', 'reason' => 'Underactive thyroid',
        'instructions' => 'On an empty stomach, 30 minutes before breakfast', 'stock' => 9, 'start' => $ymd(-900)], [['07:00', 1, null, null]]);
    $levemir = $med(['name' => 'Levemir (insulin detemir)', 'strength' => '100 units/ml', 'form' => 'injection', 'unit' => 'unit', 'colour' => 'blue',
        'reason' => 'Type 1 diabetes', 'start' => $ymd(-2000), 'expiry' => $ymd(150)], [['22:00', 18, null, null]]);
    $med(['name' => 'NovoRapid (insulin aspart)', 'strength' => '100 units/ml', 'form' => 'injection', 'unit' => 'unit', 'colour' => 'orange',
        'reason' => 'Type 1 diabetes', 'instructions' => 'With meals: carb ratio 1 unit per 8g', 'prn' => 6, 'start' => $ymd(-2000), 'expiry' => $ymd(20)], []);
    q("INSERT INTO medication_changes (user_id, medication_id, effective_date, kind, summary, reason) VALUES
        (?, ?, ?, 'started', 'Started 100mg 08:00 · 1.5 tablets · Every day', NULL),
        (?, ?, ?, 'increased', 'Increased from 300mg to 400mg a day (4 tablets a day)', 'Neurology: two breakthrough seizures')",
        [$uid, $lamo, $ymd(-400), $uid, $lamo, $ymd(-42)]);

    // Two weeks of doses: mostly taken, the odd one missed or skipped.
    foreach (q('SELECT s.*, m.id AS med FROM medication_schedules s JOIN medications m ON m.id = s.medication_id WHERE m.user_id = ? AND m.as_needed = 0', [$uid]) as $s) {
        for ($d = -14; $d <= 0; $d++) {
            $day = $today->modify("$d days");
            if (($s['valid_from'] && $s['valid_from'] > $day->format('Y-m-d')) || ($s['valid_to'] && $s['valid_to'] < $day->format('Y-m-d'))) {
                continue;
            }
            $slot = $at($day, substr($s['dose_time'], 0, 5));
            if ($slot > gmdate('Y-m-d H:i:s') || mt_rand(1, 100) <= 6) {
                continue; // upcoming, or missed
            }
            $skipped = mt_rand(1, 100) <= 3;
            q('INSERT INTO dose_logs (user_id, medication_id, schedule_id, scheduled_for, status, amount, taken_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uid, $s['med'], $s['id'], $slot, $skipped ? 'skipped' : 'taken', $skipped ? 0 : $s['dose_amount'], $slot]);
        }
    }

    // Readings: glucose four times a day, weight weekly, blood pressure now and then.
    for ($d = -20; $d <= 0; $d++) {
        $day = $today->modify("$d days");
        foreach (['07:30' => 'Fasting', '12:30' => 'Before meal', '18:30' => 'Before meal', '22:30' => 'Bedtime'] as $time => $context) {
            if ($at($day, $time) <= gmdate('Y-m-d H:i:s')) {
                $v = round(mt_rand(48, 112) / 10, 1);
                q("INSERT INTO health_readings (user_id, metric, value1, context, recorded_at) VALUES (?, 'blood_glucose', ?, ?, ?)", [$uid, $v, $context, $at($day, $time)]);
            }
        }
        if ($d % 7 === 0) {
            q("INSERT INTO health_readings (user_id, metric, value1, recorded_at) VALUES (?, 'weight', ?, ?)", [$uid, 64.2 + mt_rand(-6, 6) / 10, $at($day, '07:15')]);
        }
        if ($d % 5 === 0) {
            q("INSERT INTO health_readings (user_id, metric, value1, value2, value3, context, recorded_at) VALUES (?, 'blood_pressure', ?, ?, ?, 'Sitting', ?)",
                [$uid, mt_rand(112, 128), mt_rand(70, 82), mt_rand(62, 76), $at($day, '08:30')]);
        }
        if ($d < 0) {
            q("INSERT INTO health_readings (user_id, metric, value1, context, recorded_at) VALUES (?, 'sleep', ?, ?, ?)",
                [$uid, mt_rand(55, 84) / 10, ['Slept well', 'OK', 'Restless'][mt_rand(0, 2)], $at($day->modify('+1 day'), '07:00')]);
        }
    }
    q("INSERT INTO health_readings (user_id, metric, value1, notes, recorded_at) VALUES (?, 'symptom', 4, 'Dizzy for an hour after the lamotrigine increase', ?)", [$uid, $at($today->modify('-38 days'), '10:00')]);

    // Appointments.
    $appt = function (string $kind, ?string $title, string $when, int $mins, string $with, string $where, ?string $notes = null, ?string $outcome = null) use ($uid): int {
        q('INSERT INTO appointments (user_id, kind, title, starts_at, duration_minutes, with_whom, location, notes, outcome_notes, remind_minutes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1440)',
            [$uid, $kind, $title, $when, $mins, $with, $where, $notes, $outcome]);
        return (int) db()->lastInsertId();
    };
    $clinic = $appt('specialist', 'Diabetes clinic', $at($today->modify('+5 days'), '10:20'), 30, 'Diabetes nurse specialist', 'Bristol Royal Infirmary',
        'Bring meter and pump download');
    q('INSERT INTO appointment_questions (appointment_id, question) VALUES (?, ?), (?, ?)',
        [$clinic, 'Can we look at my overnight lows?', $clinic, 'Is it time to change my pump infusion set routine?']);
    $appt('specialist', 'Neurology review', $at($today->modify('+23 days'), '14:00'), 20, 'Epilepsy specialist nurse', 'Bristol Royal Infirmary');
    $appt('gp', null, $at($today->modify('-9 days'), '09:10'), 10, 'Dr Priya Anand', 'Riverside Medical Centre, Bristol', null,
        'Thyroid blood test normal. Keep levothyroxine at 50mcg. Repeat in 12 months.');

    // Care: routines and checks.
    $task = fn (string $area, string $kind, string $label, string $times, ?int $every, ?string $tip, ?string $amount = null) =>
        q('INSERT INTO care_tasks (user_id, area, kind, label, times, days_mask, every_days, starts_on, amount, tip) VALUES (?, ?, ?, ?, ?, 127, ?, ?, ?, ?)',
            [$uid, $area, $kind, $label, $times, $every, $ymd(-30), $amount, $tip]);
    $task('dental', 'brush', 'Brush teeth', '07:45,22:15', null, "Two minutes with fluoride toothpaste. Spit, don't rinse.");
    $task('diabetes', 'cgm', 'Change CGM sensor', '09:00', 10, 'Every 10 days. Alternate arms.');
    $task('diabetes', 'other', 'Change pump infusion set', '09:00', 3, 'Every 3 days; new site at least 3cm from the last.');
    $task('sleep', 'bedtime', 'Bedtime', '22:30', null, 'Check glucose before bed.');
    $task('activity', 'walk', 'Walk', '12:30', null, 'Carry glucose tablets.', '30 minutes');
    foreach (q('SELECT id, times, every_days FROM care_tasks WHERE user_id = ?', [$uid]) as $t) {
        for ($d = -6; $d < 0; $d++) {
            if (mt_rand(1, 100) <= 80 && (!$t['every_days'] || ((30 + $d) % (int) $t['every_days']) === 0)) {
                foreach (array_keys(explode(',', $t['times'])) as $slot) {
                    q('INSERT IGNORE INTO care_logs (user_id, task_id, log_date, slot, done_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$uid, $t['id'], $ymd($d), $slot]);
                }
            }
        }
    }
    $check = fn (string $area, string $kind, string $label, array $f) => q(
        'INSERT INTO health_checks (user_id, area, kind, label, interval_days, rule, track, last_done, due_date, quantity, unit, low_at, per_week, counted_on, items, tip, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$uid, $area, $kind, $label, $f['interval'] ?? null, $f['rule'] ?? null, $f['track'] ?? 'due', $f['last'] ?? null, $f['due'] ?? null, $f['qty'] ?? null,
            $f['unit'] ?? null, $f['low'] ?? null, $f['per_week'] ?? null, isset($f['qty']) ? $ymd(-4) : null, $f['items'] ?? null, $f['tip'] ?? null, $f['notes'] ?? null]);
    $check('diabetes', 'strips', 'Test strips', ['track' => 'stock', 'qty' => 120, 'unit' => 'strips', 'low' => 50, 'per_week' => 28]);
    $check('diabetes', 'sensors', 'CGM sensors', ['track' => 'stock', 'qty' => 3, 'unit' => 'sensors', 'low' => 2, 'per_week' => 0.7]);
    $check('diabetes', 'hba1c', 'Diabetes review (HbA1c)', ['interval' => 182, 'last' => $ymd(-170)]);
    $check('eyes', 'diabetic_eye', 'Diabetic eye screening', ['interval' => 365, 'last' => $ymd(-300)]);
    $check('emergency', 'adrenaline', 'Adrenaline auto-injector expiry', ['track' => 'expiry', 'due' => $ymd(24), 'tip' => 'Peanut allergy: carry two at all times.']);
    $check('emergency', 'adrenaline2', 'Second adrenaline auto-injector expiry', ['track' => 'expiry', 'due' => $ymd(190)]);
    $check('emergency', 'first_aid', 'First aid kit', ['track' => 'kit', 'interval' => 182, 'last' => $ymd(-150),
        'items' => "Plasters\nSterile dressings\nGlucose tablets | " . $today->modify('+8 months')->format('Y-m') . "\nGlucagon kit | " . $today->modify('+2 months')->format('Y-m') . "\nParacetamol | " . $today->modify('+14 months')->format('Y-m')]);
    $check('vaccinations', 'flu', 'Flu jab', ['rule' => 'autumn', 'last' => $ymd(-380)]);
    $check('screening', 'cervical', 'Cervical screening', ['rule' => 'screening', 'last' => $ymd(-1500)]);

    // Mind: a few weeks of check-ins, a toolkit, contacts and a plan.
    for ($d = -21; $d <= 0; $d++) {
        if (mt_rand(1, 100) <= 75) {
            $mood = mt_rand(2, 5);
            q('INSERT INTO mind_checkins (user_id, logged_at, log_date, mood, energy, motivation, anxiety, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, $at($today->modify("$d days"), '20:00'), $ymd($d), $mood, max(1, $mood - mt_rand(0, 1)), mt_rand(2, 5), mt_rand(1, 6),
                    $mood <= 2 ? 'tired,low' : ($mood >= 4 ? 'calm' : 'stressed')]);
        }
    }
    foreach (['Walk by the river', 'Call Sarah', 'Bath and a podcast', 'Breathing exercise'] as $item) {
        q('INSERT INTO mind_toolkit (user_id, item) VALUES (?, ?)', [$uid, $item]);
    }
    seed_crisis_contacts($uid);
    q("INSERT INTO mind_contacts (user_id, kind, name, detail, phone, helps_with, reach_out_days) VALUES
        (?, 'person', 'James Whitcombe (husband)', 'Next of kin; holds power of attorney', '07700 900461', 'talk,practical,company', NULL),
        (?, 'person', 'Sarah Okafor (sister)', 'Good for a chat and a walk', '07700 900738', 'talk,distraction', 7),
        (?, 'professional', 'Riverside Medical Centre', 'GP: Dr Priya Anand', '0117 496 0123', NULL, NULL)", [$uid, $uid, $uid]);
    q("INSERT INTO mind_plan (user_id, section, content) VALUES
        (?, 'warning_signs', 'Not sleeping, skipping meals, stopping checking my glucose.'),
        (?, 'coping', 'Walk by the river. Bath. Breathing exercise.'),
        (?, 'adv_meds', 'Allergic to penicillin (rash, facial swelling). Codeine makes me very sick. Peanut anaphylaxis: two adrenaline pens in my bag.'),
        (?, 'green_like', 'Up early, walking most days, seeing friends.')", [$uid, $uid, $uid, $uid]);
    q('INSERT INTO cycle_periods (user_id, start_date, end_date, flow) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)',
        [$uid, $ymd(-59), $ymd(-55), 'medium', $uid, $ymd(-31), $ymd(-27), 'heavy', $uid, $ymd(-3), null, 'medium']);
}
