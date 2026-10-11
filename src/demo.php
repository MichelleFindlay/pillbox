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
            ['demo-' . bin2hex(random_bytes(6)), 'dental,eyes,sleep,activity,diabetes,bloods,prescriptions,womens,menopause,thyroid,migraine,bp,neuro,allergies,emergency,screening,vaccinations',
                'blood_glucose,blood_ketones,blood_pressure,weight,sleep,mood,symptom']);
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

    // A month of doses (as long as the doctor's report covers): mostly taken, the odd one missed or skipped.
    foreach (q('SELECT s.*, m.id AS med FROM medication_schedules s JOIN medications m ON m.id = s.medication_id WHERE m.user_id = ? AND m.as_needed = 0', [$uid]) as $s) {
        for ($d = -30; $d <= 0; $d++) {
            $day = $today->modify("$d days");
            if (($s['valid_from'] && $s['valid_from'] > $day->format('Y-m-d')) || ($s['valid_to'] && $s['valid_to'] < $day->format('Y-m-d'))) {
                continue;
            }
            $slot = $at($day, substr($s['dose_time'], 0, 5));
            if ($slot > gmdate('Y-m-d H:i:s') || ($d < -1 && mt_rand(1, 100) <= 4)) {
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
    $check('allergies', 'aai1', 'Adrenaline auto-injector 1 expiry', ['track' => 'expiry', 'due' => $ymd(24), 'tip' => 'Peanut allergy: carry two at all times.']);
    $check('allergies', 'aai2', 'Adrenaline auto-injector 2 expiry', ['track' => 'expiry', 'due' => $ymd(190)]);
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
    seed_demo_conditions($uid, $today, $at, $ymd, ['levo' => $levo, 'levemir' => $levemir]);
}

/**
 * Nell's long-term conditions in the Care categories: diabetes, thyroid, perimenopause,
 * migraine, blood pressure and ADHD, so every page has something to show.
 */
function seed_demo_conditions(int $uid, DateTimeImmutable $today, callable $at, callable $ymd, array $meds): void
{
    // Allergies: matching the Mind advance statement (penicillin, codeine, peanuts).
    foreach ([['Penicillin', 'medicine', 'penicillins', 'allergy', 'hives,swelling', 'severe', 'gp', '2004', 'Given clarithromycin instead.'],
              ['Peanuts', 'food', null, 'allergy', 'swelling,throat,breathing', 'anaphylaxis', 'clinic', 'childhood', null],
              ['Codeine', 'medicine', null, 'intolerance', 'stomach', 'moderate', 'self', null, 'Very sick; paracetamol is fine.'],
              ['Grass pollen', 'environment', null, 'allergy', 'nose,eyes', 'mild', 'gp', null, null]] as $a) {
        q('INSERT INTO allergies (user_id, allergen, category, drug_class, kind, reactions, severity, confirmed, since, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$uid, ...$a]);
    }
    q("INSERT INTO allergy_profiles (user_id, aai, aai_brand, aai_where) VALUES (?, 1, 'EpiPen', 'two in my handbag, a spare in my desk at work')", [$uid]);
    add_preset_check($uid, 'allergies', 'aai_trainer');
    $utc = fn (int $days, string $time) => $at($today->modify("$days days"), $time);
    // Medicines: a supplement, and a triptan for migraines.
    q("INSERT INTO medications (user_id, name, strength, form, dose_unit, colour, reason, start_date, is_supplement, supply_type, supply_price, stock_count)
       VALUES (?, 'Vitamin D', '10 micrograms', 'tablet', 'tablet', 'yellow', 'Bones (perimenopause)', ?, 1, 'otc', 4.99, 60)", [$uid, $ymd(-120)]);
    $vitd = (int) db()->lastInsertId();
    q("INSERT INTO medication_schedules (medication_id, dose_time, dose_amount, days_mask) VALUES (?, '08:00:00', 1, 127)", [$vitd]);
    $sched = (int) db()->lastInsertId();
    for ($d = -30; $d <= 0; $d++) {
        if ($utc($d, '08:00') <= gmdate('Y-m-d H:i:s') && ($d >= -1 || mt_rand(1, 100) > 8)) {
            q("INSERT INTO dose_logs (user_id, medication_id, schedule_id, scheduled_for, status, amount, taken_at) VALUES (?, ?, ?, ?, 'taken', 1, ?)", [$uid, $vitd, $sched, $utc($d, '08:00'), $utc($d, '08:05')]);
        }
    }
    q("INSERT INTO medications (user_id, name, strength, form, dose_unit, colour, reason, instructions, prescriber, as_needed, prn_dose_amount, prn_max_daily, stock_count, start_date)
       VALUES (?, 'Sumatriptan', '50mg', 'tablet', 'tablet', 'red', 'Migraine', 'At the start of the headache (not during the aura)', 'Dr Priya Anand', 1, 1, 2, 4, ?)", [$uid, $ymd(-300)]);
    $suma = (int) db()->lastInsertId();
    q("UPDATE medications SET brand = 'Accord' WHERE id = ?", [$meds['levo']]);
    q("INSERT INTO medication_changes (user_id, medication_id, effective_date, kind, summary) VALUES (?, ?, ?, 'increased', 'Increased from 25mcg to 50mcg a day')", [$uid, $meds['levo'], $ymd(-250)]);

    // Diabetes: type 1, drives; insulin and carbs, a hypo, ketones and the annual checks.
    q("INSERT INTO diabetes_profiles (user_id, type, target_low, target_high, hba1c_target, drives) VALUES (?, '1', 4.0, 10.0, 48, 1)", [$uid]);
    for ($d = -6; $d <= 0; $d++) {
        foreach (['08:00' => [5, 45], '12:45' => [6, 55], '18:45' => [7, 65]] as $time => [$units, $carbs]) {
            if ($utc($d, $time) <= gmdate('Y-m-d H:i:s')) {
                q("INSERT INTO insulin_logs (user_id, taken_at, kind, units, carbs, medication_id) VALUES (?, ?, 'bolus', ?, ?, NULL)", [$uid, $utc($d, $time), $units + mt_rand(-1, 1), $carbs + mt_rand(-10, 10)]);
            }
        }
        if ($utc($d, '22:00') <= gmdate('Y-m-d H:i:s')) {
            q("INSERT INTO insulin_logs (user_id, taken_at, kind, units, medication_id) VALUES (?, ?, 'basal', 18, ?)", [$uid, $utc($d, '22:00'), $meds['levemir']]);
        }
    }
    foreach ([[-12, '03:10', 3.4], [-6, '16:20', 3.6]] as [$d, $time, $g]) {
        q("INSERT INTO health_readings (user_id, metric, value1, context, notes, recorded_at) VALUES (?, 'blood_glucose', ?, 'Hypo symptoms', 'Hypo', ?)", [$uid, $g, $utc($d, $time)]);
    }
    q("INSERT INTO hypo_events (user_id, at, glucose, treated_with, notes) VALUES (?, ?, 3.6, '5 glucose tablets', 'After a long walk without a snack')", [$uid, $utc(-6, '16:20')]);
    q("INSERT INTO health_readings (user_id, metric, value1, recorded_at) VALUES (?, 'blood_ketones', 0.3, ?)", [$uid, $utc(-2, '09:00')]);
    foreach ([[-380, 56], [-200, 52], [-20, 49]] as [$d, $v]) {
        q("INSERT INTO lab_results (user_id, test, value, unit, taken_at, lab_low, lab_high) VALUES (?, 'hba1c', ?, 'mmol/mol', ?, 20, 41)", [$uid, $v, $utc($d, '09:30')]);
    }
    foreach (['dm_hba1c' => -20, 'dm_bp' => -20, 'dm_cholesterol' => -20, 'dm_kidney' => -20, 'dm_acr' => -200, 'dm_feet' => -300, 'dm_weight' => -20, 'dm_smoking' => -20] as $kind => $last) {
        add_preset_check($uid, 'diabetes', $kind);
        q('UPDATE health_checks SET last_done = ? WHERE user_id = ? AND kind = ?', [$ymd($last), $uid, $kind]);
    }

    // Thyroid: TSH and free T4 around the dose change, a clinic target range, weekly symptoms.
    foreach ([[-400, 6.8, 11.2], [-160, 3.1, 14.0], [-70, 1.9, 15.5], [-9, 1.8, 15.1]] as [$d, $tsh, $ft4]) {
        q("INSERT INTO lab_results (user_id, test, value, unit, taken_at, timing, lab_low, lab_high) VALUES (?, 'tsh', ?, 'mU/L', ?, 'unknown', 0.27, 4.2)", [$uid, $tsh, $utc($d, '09:00')]);
        q("INSERT INTO lab_results (user_id, test, value, unit, taken_at, lab_low, lab_high) VALUES (?, 'free_t4', ?, 'pmol/L', ?, 12, 22)", [$uid, $ft4, $utc($d, '09:00')]);
    }
    q("INSERT INTO lab_targets (user_id, test, low, high, note) VALUES (?, 'tsh', 0.5, 2.5, 'Dr Anand')", [$uid]);
    for ($w = 5; $w >= 0; $w--) {
        $monday = $today->modify('monday this week')->modify("-$w weeks")->format('Y-m-d');
        q('INSERT INTO thyroid_weeks (user_id, week_start, scores) VALUES (?, ?, ?)', [$uid, $monday, json_encode(['tired' => min(3, (int) ($w / 2) + mt_rand(0, 1)), 'cold' => $w > 3 ? 1 : 0, 'mood_fog' => mt_rand(0, 1), 'sleep' => mt_rand(0, 1)])]);
    }

    // Perimenopause: a fortnight of the symptom diary.
    q("INSERT INTO menopause_profiles (user_id, stage, cause, work_notes) VALUES (?, 'peri', 'natural', 'Desk fan agreed with my manager. Flexible start on bad nights.')", [$uid]);
    for ($d = -13; $d <= 0; $d++) {
        $caffeine = mt_rand(0, 2) === 0;
        q('INSERT INTO menopause_logs (user_id, log_date, flushes, flush_severity, scores, triggers) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $ymd($d), mt_rand(1, 3) + ($caffeine ? 3 : 0),
            mt_rand(1, 2), json_encode(['night_sweats' => mt_rand(0, 2), 'sleep' => mt_rand(1, 2), 'brain_fog' => mt_rand(0, 2), 'joints' => mt_rand(0, 1), 'irritability' => mt_rand(0, 1)]),
            $caffeine ? 'caffeine' : (mt_rand(0, 3) === 0 ? 'stress' : null)]);
    }

    // Migraine: a few, around her period, some with aura, treated with sumatriptan.
    foreach ([[-60, '07:30', 14, true, true], [-45, '15:00', 5, false, false], [-32, '06:45', 20, true, false], [-18, '18:00', 4, false, false], [-4, '07:15', 11, true, true]] as [$d, $time, $hours, $migraine, $aura]) {
        $start = $utc($d, $time);
        q('INSERT INTO headache_episodes (user_id, started_at, ended_at, severity, location, migraine, aura, symptoms, triggers) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $uid, $start, gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + $hours * 3600), $migraine ? 3 : 1, $migraine ? 'one_side' : 'front', (int) $migraine, (int) $aura,
            $migraine ? 'nausea,light,throbbing' : null, $migraine ? 'period,sleep' : 'screen']);
        $ep = (int) db()->lastInsertId();
        q('INSERT INTO episode_meds (episode_id, medication_id, name, taken_at, helped) VALUES (?, ?, ?, ?, ?)',
            [$ep, $migraine ? $suma : null, $migraine ? 'Sumatriptan 50mg' : 'Paracetamol 500mg', gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 3600), $migraine ? 2 : 1]);
    }

    // Blood pressure: a home monitoring week under way.
    q('INSERT INTO bp_profiles (user_id, monitor, cuff, validated, session_start) VALUES (?, ?, ?, 1, ?)', [$uid, 'Omron M3', 'standard', $ymd(-4)]);
    for ($d = -4; $d <= 0; $d++) {
        foreach (['07:30', '07:31', '21:00', '21:01'] as $time) {
            if ($utc($d, $time) <= gmdate('Y-m-d H:i:s')) {
                q("INSERT INTO health_readings (user_id, metric, value1, value2, value3, context, recorded_at) VALUES (?, 'blood_pressure', ?, ?, ?, 'Home monitoring', ?)",
                    [$uid, mt_rand(122, 136), mt_rand(76, 86), mt_rand(60, 74), $utc($d, $time)]);
            }
        }
    }

    // ADHD: self-identified, on a Right to Choose list; a communication passport and prep notes.
    q('INSERT INTO nd_profiles (user_id, identity, passport, raf_asked) VALUES (?, ?, ?, ?)', [$uid, json_encode(['adhd' => 'waiting']), json_encode([
        'communicate' => 'Email or text is easier for me than phone calls. Please write down anything I need to do.',
        'processing' => 'Give me a moment to answer, and one question at a time.',
        'overwhelmed' => 'During a hypo I may be confused or slow to answer: check my glucose first.',
        'appointments' => 'Morning appointments are best. Somewhere quiet to wait helps.',
        'contact' => 'James Whitcombe (husband), 07700 900461',
    ]), $ymd(-60)]);
    q("INSERT INTO nd_waitlist (user_id, assessment, provider, route, referred_on) VALUES (?, 'adhd', 'Psychiatry-UK', 'rtc', ?)", [$uid, $ymd(-430)]);
    foreach ([['childhood', 'School reports: "bright but easily distracted", "rushes work".'], ['daily', 'Lose my keys and glasses most days; set five alarms in the morning.'],
        ['strengths', 'Calm in a crisis; can focus for hours on things I enjoy.']] as [$cat, $note]) {
        q('INSERT INTO nd_prep (user_id, category, note) VALUES (?, ?, ?)', [$uid, $cat, $note]);
    }
}
