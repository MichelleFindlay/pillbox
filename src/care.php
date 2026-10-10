<?php
declare(strict_types=1);

/*
 * Care: routines (tasks at set local times, on chosen weekdays or every N days,
 * ticked off on the Today page) and checks (things due every few months or years,
 * see health_checks.php), grouped into areas the user switches on in their profile.
 * Routine logs are keyed by the user's local date and the index of the task's time ("slot").
 */

/*
 * Areas. 'presets' are routine tasks: label, times, tip, plus optional every (days),
 * days (weekday mask), amount and remind. 'checks' are due-date items: label, tip,
 * plus interval (days) or rule. 'default' = switched on for new users.
 * Tips follow NHS advice where there is some.
 */
const CARE_AREAS = [
    'dental' => [
        'title' => 'Dental care', 'short' => 'Dental', 'icon' => '&#x1F9B7;', 'default' => true, 'appointment' => 'dentist',
        'intro' => 'The NHS advises brushing twice a day for about 2 minutes with fluoride toothpaste, and cleaning between your teeth every day.',
        'help'  => 'Your dentist will tell you how often to have a check-up: anything from every 3 months to every 2 years.',
        'presets' => [
            'brush'      => ['label' => 'Brush teeth', 'times' => ['08:00', '21:00'], 'tip' => "Two minutes with fluoride toothpaste, last thing at night and one other time. Spit, don't rinse."],
            'between'    => ['label' => 'Clean between teeth', 'times' => ['21:00'], 'tip' => 'Floss or interdental brushes once a day, before you brush.'],
            'mouthwash'  => ['label' => 'Mouthwash', 'times' => ['13:00'], 'tip' => "Use it at a different time from brushing, so it doesn't wash away the fluoride."],
            'tongue'     => ['label' => 'Clean tongue', 'times' => ['08:00'], 'tip' => 'A tongue scraper or your toothbrush, from back to front.'],
            'retainer'   => ['label' => 'Clean retainer', 'times' => ['08:00'], 'tip' => 'Brush it gently with cool water. Toothpaste can scratch it.'],
            'dentures'   => ['label' => 'Clean dentures', 'times' => ['21:00'], 'tip' => 'Brush them, then soak overnight in water or denture cleaner.'],
            'toothbrush' => ['label' => 'Replace toothbrush or brush head', 'times' => ['08:00'], 'every' => 91, 'tip' => 'Every 3 months, or sooner if the bristles are splayed.'],
        ],
        'checks' => [
            'dental_checkup' => ['label' => 'Dental check-up', 'interval' => 365, 'tip' => 'Change how often to match what your dentist recommends.'],
            'hygienist'      => ['label' => 'Hygienist', 'interval' => 182, 'tip' => 'If your dentist recommends regular cleaning.'],
        ],
    ],
    'eyes' => [
        'title' => 'Eye care', 'short' => 'Eyes', 'icon' => '&#x1F441;&#xFE0F;', 'default' => true, 'appointment' => 'optician',
        'intro' => 'Small daily habits help with tired, dry or sore eyes, and with looking after contact lenses.',
        'help'  => 'Get urgent help from NHS 111 or A&E for sudden changes in your sight, a painful red eye, or new flashes of light or floaters.',
        'presets' => [
            'screen'         => ['label' => 'Screen break', 'times' => ['11:00', '15:00'], 'tip' => 'The 20-20-20 rule: every 20 minutes, look at something 20 feet away for 20 seconds.'],
            'lids'           => ['label' => 'Clean eyelids', 'times' => ['08:00', '21:00'], 'tip' => 'Warm compress for 5 to 10 minutes, gently massage the lids, then wipe along the lashes. Helps blepharitis and dry eyes.'],
            'lenses_out'     => ['label' => 'Take contact lenses out', 'times' => ['22:00'], 'tip' => 'Unless your optician says you can sleep in them. Store them in fresh solution, never water.'],
            'lens_case'      => ['label' => 'Clean contact lens case', 'times' => ['22:00'], 'tip' => 'Rinse it with solution, never tap water, and leave it upside down to air-dry.'],
            'replace_lenses' => ['label' => 'Replace contact lenses', 'times' => ['08:00'], 'every' => 30, 'tip' => 'Start a fresh pair. Change how often to match your lenses (e.g. every 14 days).'],
            'makeup'         => ['label' => 'Remove eye make-up', 'times' => ['22:00'], 'tip' => "Before bed, so it doesn't block the glands along your eyelids."],
        ],
        'checks' => [
            'eye_test'          => ['label' => 'Eye test', 'interval' => 730, 'tip' => 'The NHS recommends one at least every 2 years, or more often if your optician advises it.'],
            'diabetic_eye'      => ['label' => 'Diabetic eye screening', 'interval' => 365, 'tip' => 'Every 1 to 2 years if you have diabetes, as your screening service advises.'],
        ],
    ],
    'sleep' => [
        'title' => 'Sleep', 'short' => 'Sleep', 'icon' => '&#x1F319;', 'default' => true, 'appointment' => null,
        'intro' => 'Most adults need 7 to 9 hours a night. Going to bed and getting up at about the same time every day helps most.',
        'help'  => 'See a GP if poor sleep has lasted for months or is affecting your day, or if someone notices you stop breathing, '
                 . 'gasp or choke in your sleep (possible sleep apnoea). If you use CPAP, your sleep service can help with mask fit and supplies.',
        'presets' => [
            'wind_down'     => ['label' => 'Start winding down', 'times' => ['22:00'], 'remind' => true, 'tip' => 'Screens off and lights low. Something relaxing: a bath, reading, or gentle stretches.'],
            'bedtime'       => ['label' => 'Bedtime', 'times' => ['22:30'], 'remind' => true, 'tip' => 'About the same time every night, weekends too.'],
            'cpap_mask'     => ['label' => 'Clean CPAP mask', 'times' => ['08:00'], 'tip' => 'Wipe or wash the cushion with warm soapy water and let it air-dry.'],
            'cpap_water'    => ['label' => 'Empty CPAP humidifier', 'times' => ['08:00'], 'tip' => 'Empty it and let it dry, then refill with fresh water at night (distilled if your supplier says so).'],
            'cpap_tubing'   => ['label' => 'Wash CPAP tubing', 'times' => ['09:00'], 'every' => 7, 'tip' => 'Warm soapy water, rinse well, then hang it up to dry.'],
            'cpap_filter'   => ['label' => 'Replace CPAP filter', 'times' => ['09:00'], 'every' => 28, 'tip' => "Disposable filters are often changed every 2 to 4 weeks. Follow your machine's or sleep service's guidance."],
            'cpap_cushion'  => ['label' => 'Replace CPAP mask cushion', 'times' => ['09:00'], 'every' => 30, 'tip' => 'Often monthly, or sooner if it stops sealing. Your sleep service can tell you how to order one.'],
            'cpap_new_mask' => ['label' => 'Replace CPAP mask', 'times' => ['09:00'], 'every' => 91, 'tip' => 'Many masks are replaced every 3 to 6 months. Check with your sleep service.'],
        ],
        'checks' => [
            'cpap_review' => ['label' => 'CPAP / sleep clinic review', 'interval' => 365, 'tip' => 'Change how often to match your sleep service.'],
        ],
    ],
    'activity' => [
        'title' => 'Physical activity & rehab', 'short' => 'Activity', 'icon' => '&#x1F3C3;', 'default' => true, 'appointment' => 'physio',
        'intro' => 'The NHS advises adults to do at least 150 minutes of moderate activity a week, and strengthening activities on at least 2 days a week. '
                 . 'Physio exercises work best done little and often, exactly as shown.',
        'help'  => 'Stop and check with your physio or GP if an exercise causes sharp pain, or pain that is worse the next day.',
        'presets' => [
            'physio'   => ['label' => 'Physio exercises', 'times' => ['09:00', '18:00'], 'amount' => '3 sets of 10', 'tip' => 'The exercises your physio gave you. Change the amount and days to match your plan.'],
            'stretch'  => ['label' => 'Stretching', 'times' => ['08:00'], 'amount' => '10 minutes', 'tip' => 'Hold each stretch for about 30 seconds without bouncing.'],
            'mobility' => ['label' => 'Mobility routine', 'times' => ['19:00'], 'amount' => '10 minutes', 'tip' => 'Gentle movement through a full range: neck, shoulders, back, hips, knees and ankles.'],
            'strength' => ['label' => 'Strength exercises', 'times' => ['18:00'], 'days' => 1 | 8, 'amount' => '20 minutes', 'tip' => 'At least 2 days a week: e.g. squats, wall press-ups, resistance bands or carrying shopping.'],
            'walk'     => ['label' => 'Walk', 'times' => ['12:30'], 'amount' => '30 minutes', 'tip' => 'Brisk enough to raise your heart rate: you can talk, but not sing.'],
            'balance'  => ['label' => 'Balance exercises', 'times' => ['10:00'], 'days' => 2 | 16, 'amount' => '10 minutes', 'tip' => 'e.g. standing on one leg near something to hold. Helps prevent falls.'],
        ],
        'checks' => [
            'physio_review' => ['label' => 'Physio review', 'interval' => 42, 'tip' => 'Set the date of your next physio appointment, or how often you go.'],
        ],
    ],
    'feet' => [
        'title' => 'Foot health', 'short' => 'Feet', 'icon' => '&#x1F9B6;', 'default' => false, 'appointment' => 'podiatry',
        'intro' => 'Checking your feet every day is important if you have diabetes, poor circulation or numbness, because small problems can get serious quickly.',
        'help'  => 'If you have diabetes, get urgent advice from your GP, foot protection team or NHS 111 for a cut, blister or sore that is not healing, '
                 . 'or a foot that is red, hot or swollen.',
        'presets' => [
            'foot_check' => ['label' => 'Check your feet', 'times' => ['21:00'], 'tip' => 'Look for cuts, blisters, colour changes, swelling or hard skin, using a mirror for the soles.'],
            'moisturise' => ['label' => 'Moisturise feet', 'times' => ['21:00'], 'tip' => 'Not between the toes, where skin needs to stay dry.'],
            'wash_feet'  => ['label' => 'Wash and dry feet', 'times' => ['08:00'], 'tip' => 'Warm, not hot, water. Dry carefully between the toes.'],
            'nails'      => ['label' => 'Nail care', 'times' => ['10:00'], 'every' => 21, 'tip' => 'Cut straight across, not too short. If you have diabetes and find this hard, ask your podiatrist.'],
        ],
        'checks' => [
            'diabetic_foot' => ['label' => 'Diabetes foot check', 'interval' => 365, 'tip' => 'At least once a year if you have diabetes, usually at your diabetes review.'],
            'podiatry'      => ['label' => 'Podiatry appointment', 'interval' => 91, 'tip' => 'Change how often to match your podiatrist.'],
        ],
    ],
    'womens' => [
        'title' => "Women's health", 'short' => "Women's", 'icon' => '&#x2640;&#xFE0F;', 'default' => false, 'appointment' => null,
        'intro' => 'Track your periods and keep contraception renewals and reviews on time.',
        'help'  => 'See a GP about very heavy or painful periods, bleeding between periods or after sex, or any bleeding after the menopause.',
        'presets' => [],
        'checks' => [
            'pill_review' => ['label' => 'Pill review', 'interval' => 365, 'tip' => 'A check of your blood pressure and weight, usually yearly, to keep getting your pill.'],
            'implant'     => ['label' => 'Implant replacement', 'interval' => 1095, 'tip' => 'The implant lasts 3 years. Book the replacement before the date.'],
            'coil'        => ['label' => 'Coil replacement', 'interval' => 1826, 'tip' => 'Hormonal coils (IUS) last 3 to 8 years and copper coils 5 to 10, depending on type. Set it to what you were told.'],
            'injection'   => ['label' => 'Contraceptive injection', 'interval' => 91, 'tip' => 'Every 13 weeks. Get it in time to stay protected.'],
        ],
    ],
    'hearing' => [
        'title' => 'Hearing', 'short' => 'Hearing', 'icon' => '&#x1F442;', 'default' => true, 'appointment' => 'hearing',
        'intro' => 'Have your hearing tested every few years, and more often if you are around loud noise.',
        'help'  => 'Get urgent help from NHS 111 for sudden hearing loss in one or both ears.',
        'presets' => [
            'aids_clean'  => ['label' => 'Clean hearing aids', 'times' => ['22:00'], 'tip' => 'Wipe them with a dry cloth and clear the openings with the brush or wax pick.'],
            'batteries'   => ['label' => 'Change hearing aid batteries', 'times' => ['08:00'], 'every' => 7, 'tip' => 'Change how often to match how long yours last.'],
            'ear_protect' => ['label' => 'Ear protection', 'times' => ['08:00'], 'tip' => 'Earplugs or defenders for loud work, concerts or power tools.'],
        ],
        'checks' => [
            'hearing_test' => ['label' => 'Hearing test', 'interval' => 1095, 'tip' => 'Every few years; every year if you work around loud noise.'],
            'aid_service'  => ['label' => 'Hearing aid service', 'interval' => 365, 'tip' => 'New tubes, filters and a check of the settings.'],
        ],
    ],
    'skin' => [
        'title' => 'Skin', 'short' => 'Skin', 'icon' => '&#x1F9F4;', 'default' => true, 'appointment' => null,
        'intro' => 'Check your skin and moles once a month, and see a GP about any changes. A yearly check is a good idea if you are at higher risk: '
                 . 'lots of moles, fair skin that burns easily, past skin cancer or a family history of it.',
        'help'  => 'See a GP about a mole that changes size, shape or colour, has uneven edges, bleeds or itches, or a sore that does not heal.',
        'presets' => [
            'mole_check' => ['label' => 'Skin and mole check', 'times' => ['09:00'], 'every' => 30, 'remind' => true, 'tip' => 'ABCDE: Asymmetry, Border, Colour, Diameter (over 6mm), Evolving. Ask someone to check your back.'],
            'sunscreen'  => ['label' => 'Sunscreen', 'times' => ['08:00'], 'tip' => 'SPF 30 or more and 4 or 5 stars, on sunny days and in summer.'],
            'moisturise' => ['label' => 'Moisturise', 'times' => ['21:00'], 'tip' => 'For dry skin or eczema, use emollients as often as you need.'],
        ],
        'checks' => [
            'skin_check' => ['label' => 'Skin check (GP or dermatologist)', 'interval' => 365, 'tip' => 'Yearly if you are at higher risk, or as your doctor advises.'],
        ],
    ],
    'screening' => [
        'title' => 'Screening', 'short' => 'Screening', 'icon' => '&#x1F50E;', 'default' => true, 'appointment' => null,
        'intro' => 'NHS screening programmes, with the next due date worked out from your date of birth and when you were last screened.',
        'help'  => "Don't wait for screening if you have symptoms: see a GP. Intervals are for England; Scotland, Wales and Northern Ireland differ slightly.",
        'presets' => [],
        'checks' => [], // generated from the profile: see SCREENING_PROGRAMMES
    ],
    'vaccinations' => [
        'title' => 'Vaccinations', 'short' => 'Vaccines', 'icon' => '&#x1F489;', 'default' => true, 'appointment' => 'vaccination',
        'intro' => 'Seasonal jabs, boosters and travel vaccinations, with when each is next due.',
        'help'  => 'Your GP surgery or pharmacy can tell you which vaccines you are eligible for. For travel, ask 8 weeks before you go.',
        'presets' => [],
        'checks' => [
            'flu'     => ['label' => 'Flu jab', 'rule' => 'autumn', 'tip' => 'Every autumn, if you are eligible (e.g. 65 or over, pregnant, or with some long-term conditions).'],
            'covid'   => ['label' => 'COVID-19 booster', 'rule' => 'autumn', 'tip' => 'Offered each autumn to those most at risk, and in spring to some groups. Check if you are eligible.'],
            'tetanus' => ['label' => 'Tetanus', 'interval' => 3652, 'tip' => 'In the UK, 5 doses give lasting protection. A booster may be advised after some wounds, or for travel if your last was over 10 years ago.'],
            'travel'  => ['label' => 'Travel vaccination', 'tip' => 'e.g. hepatitis A booster or typhoid. Set the date it is due, and rename it.'],
        ],
    ],
];
const CARE_MAX_TIMES = 4;

function care_area(string $area): array
{
    return CARE_AREAS[$area] ?? CARE_AREAS['dental'];
}

/** @return string[] the Care areas this user has switched on, in display order */
function user_care_areas(array $user): array
{
    if ($user['care_areas'] === null) {
        return array_keys(array_filter(CARE_AREAS, fn ($a) => $a['default']));
    }
    $chosen = explode(',', (string) $user['care_areas']);
    return array_values(array_filter(array_keys(CARE_AREAS), fn ($k) => in_array($k, $chosen, true)));
}

/** A routine preset with every field filled in. */
function care_preset(string $area, string $kind): ?array
{
    $p = CARE_AREAS[$area]['presets'][$kind] ?? null;
    return $p === null ? null : $p + ['every' => null, 'days' => EVERY_DAY, 'amount' => null, 'remind' => false];
}

/** A user's tasks, for one area or (null) all of them. */
function care_tasks(int $userId, ?string $area = null): array
{
    return $area === null
        ? q('SELECT * FROM care_tasks WHERE user_id = ? ORDER BY SUBSTRING(times, 1, 5), label', [$userId])->fetchAll()
        : q('SELECT * FROM care_tasks WHERE user_id = ? AND area = ? ORDER BY SUBSTRING(times, 1, 5), label', [$userId, $area])->fetchAll();
}

function find_care_task(int $id, int $userId): ?array
{
    return q('SELECT * FROM care_tasks WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
}

/** @return string[] the task's times as 'HH:MM', in order */
function care_times(array $task): array
{
    return array_values(array_filter(explode(',', (string) $task['times'])));
}

function care_time_label(string $time): string
{
    $hour = (int) substr($time, 0, 2);
    return match (true) {
        $hour < 12 => 'Morning',
        $hour < 17 => 'Afternoon',
        default    => 'Evening',
    };
}

function care_repeat_label(array $task): string
{
    return $task['every_days'] ? ucfirst(care_every_label((int) $task['every_days'])) : days_label((int) $task['days_mask']);
}

/** "every 2 weeks", "every 3 months", "every 30 days" */
function care_every_label(int $n): string
{
    return match (true) {
        $n === 1 => 'every day',
        $n === 7 => 'every week',
        $n % 7 === 0 && $n <= 56 => 'every ' . ($n / 7) . ' weeks',
        $n === 91  => 'every 3 months',
        $n === 182 => 'every 6 months',
        $n === 365 => 'every year',
        default => "every $n days",
    };
}

/** Is the task due on this local date? */
function care_due(array $task, DateTimeImmutable $day): bool
{
    $ymd = $day->format('Y-m-d');
    if ($ymd < $task['starts_on']) {
        return false;
    }
    if ($task['every_days']) {
        return (new DateTimeImmutable($task['starts_on']))->diff(new DateTimeImmutable($ymd))->days % (int) $task['every_days'] === 0;
    }
    return ((int) $task['days_mask'] & weekday_bit($day)) !== 0;
}

/** For an every-N-days task: the next local date it's due, from $from on. */
function care_next_due(array $task, DateTimeImmutable $from): ?DateTimeImmutable
{
    for ($i = 0, $day = $from; $i < 400; $i++, $day = $day->modify('+1 day')) {
        if (care_due($task, $day)) {
            return $day;
        }
    }
    return null;
}

/**
 * Ticks between two local dates (inclusive).
 * @return array<string, array> keyed "task_id|Y-m-d|slot"
 */
function care_logs(int $userId, string $from, string $to): array
{
    $logs = [];
    foreach (q('SELECT * FROM care_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?', [$userId, $from, $to]) as $log) {
        $logs[$log['task_id'] . '|' . $log['log_date'] . '|' . $log['slot']] = $log;
    }
    return $logs;
}

function care_done(array $logs, array $task, DateTimeImmutable $day, int $slot): bool
{
    return isset($logs[$task['id'] . '|' . $day->format('Y-m-d') . '|' . $slot]);
}

/** @return array{0: int, 1: int} [done, due] for one day */
function care_day_progress(array $tasks, array $logs, DateTimeImmutable $day): array
{
    $done = $due = 0;
    foreach ($tasks as $t) {
        if (!care_due($t, $day)) {
            continue;
        }
        foreach (array_keys(care_times($t)) as $slot) {
            $due++;
            $done += care_done($logs, $t, $day, $slot) ? 1 : 0;
        }
    }
    return [$done, $due];
}

/**
 * Days in a row with every task in $tasks done, counting today only once it's complete
 * (so the streak doesn't drop to zero first thing in the morning). Days with nothing
 * due are passed over.
 */
function care_streak(int $userId, array $tasks, DateTimeImmutable $today): int
{
    if (!$tasks) {
        return 0;
    }
    $firstDay = min(array_column($tasks, 'starts_on'));
    $from = max($today->modify('-365 days'), new DateTimeImmutable($firstDay, $today->getTimezone()));
    $logs = care_logs($userId, $from->format('Y-m-d'), $today->format('Y-m-d'));
    [$done, $due] = care_day_progress($tasks, $logs, $today);
    $streak = $due > 0 && $done === $due ? 1 : 0;
    for ($day = $today->modify('-1 day'); $day >= $from; $day = $day->modify('-1 day')) {
        [$done, $due] = care_day_progress($tasks, $logs, $day);
        if ($due === 0) {
            continue;
        }
        if ($done < $due) {
            break;
        }
        $streak++;
    }
    return $streak;
}

/**
 * Check a task form. @return array{0: array, 1: string[]} [values to store, errors]
 */
function care_task_from_post(string $area): array
{
    $errors = [];
    $label = trim(mb_substr(post('label'), 0, 100));
    if ($label === '') {
        $errors[] = 'Give the task a name.';
    }
    $times = [];
    $badTime = false;
    foreach ((array) ($_POST['times'] ?? []) as $t) {
        $t = trim((string) $t);
        if ($t === '') {
            continue;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $t)) {
            $errors[] = 'Enter times like 08:00 or 21:30.';
            $badTime = true;
            break;
        }
        $times[] = substr($t, 0, 5);
    }
    $times = array_values(array_unique($times));
    sort($times);
    if (!$times && !$badTime) {
        $errors[] = 'Add at least one time of day.';
    }
    if (count($times) > CARE_MAX_TIMES) {
        $errors[] = 'Up to ' . CARE_MAX_TIMES . ' times a day.';
    }

    $mask = 0;
    $everyDays = null;
    $startsOn = local_today()->format('Y-m-d');
    if (post('repeat') === 'interval') {
        $everyDays = (int) post('every_days');
        if ($everyDays < 2 || $everyDays > 366) {
            $errors[] = 'Repeat every 2 to 366 days.';
        }
        $start = parse_date(post('starts_on'));
        if (!$start) {
            $errors[] = 'Enter the date to count from.';
        } else {
            $startsOn = $start->format('Y-m-d');
        }
        $mask = EVERY_DAY;
    } else {
        foreach ((array) ($_POST['days'] ?? []) as $bit) {
            $mask |= (int) $bit & EVERY_DAY;
        }
        if ($mask === 0) {
            $errors[] = 'Choose at least one day.';
        }
    }
    $kind = post('kind');
    return [[
        'area'       => $area,
        'kind'       => care_preset($area, $kind) ? $kind : 'other',
        'label'      => $label,
        'times'      => implode(',', $times),
        'days_mask'  => $mask,
        'every_days' => $everyDays,
        'starts_on'  => $startsOn,
        'amount'     => trim(mb_substr(post('amount'), 0, 60)) ?: null,
        'tip'        => trim(mb_substr(post('tip'), 0, 255)) ?: null,
        'remind'     => post('remind') === '1' ? 1 : 0,
    ], $errors];
}

/**
 * Calendar feed events for tasks with reminders on: one repeating event per time of
 * day, with an alarm when it starts. Times are "floating" (no time zone), so they
 * follow the phone's clock, which is what you want for bedtime when travelling.
 */
function care_ics_events(array $tasks, string $host): string
{
    $ics = '';
    $byday = [1 => 'MO', 2 => 'TU', 4 => 'WE', 8 => 'TH', 16 => 'FR', 32 => 'SA', 64 => 'SU'];
    foreach ($tasks as $t) {
        if (!$t['remind']) {
            continue;
        }
        $mask = (int) $t['days_mask'];
        $rule = match (true) {
            (bool) $t['every_days'] => 'FREQ=DAILY;INTERVAL=' . (int) $t['every_days'],
            $mask === EVERY_DAY     => 'FREQ=DAILY',
            default                 => 'FREQ=WEEKLY;BYDAY=' . implode(',', array_values(array_filter($byday, fn ($bit) => ($mask & $bit) !== 0, ARRAY_FILTER_USE_KEY))),
        };
        $area = care_area($t['area']);
        // Start on the first day it's actually due: calendars count DTSTART as an occurrence even if the rule doesn't match it.
        $first = care_next_due($t, new DateTimeImmutable($t['starts_on']));
        if (!$first) {
            continue;
        }
        foreach (care_times($t) as $slot => $time) {
            $ics .= ics_line('BEGIN:VEVENT')
                . ics_line('UID:care-' . $t['id'] . '-' . $slot . '@' . $host)
                . ics_line('DTSTAMP:' . ics_utc($t['created_at']))
                . ics_line('DTSTART:' . $first->format('Ymd') . 'T' . str_replace(':', '', $time) . '00')
                . ics_line('DURATION:PT5M')
                . ics_line('RRULE:' . $rule)
                . ics_line('SUMMARY:' . ics_text($t['label'] . ($t['amount'] ? ' (' . $t['amount'] . ')' : '')))
                . ($t['tip'] ? ics_line('DESCRIPTION:' . ics_text($t['tip'])) : '')
                . ics_line('CATEGORIES:' . ics_text($area['title']))
                . ics_line('TRANSP:TRANSPARENT')
                . ics_line('BEGIN:VALARM')
                . ics_line('ACTION:DISPLAY')
                . ics_line('DESCRIPTION:' . ics_text($t['label']))
                . ics_line('TRIGGER:PT0M')
                . ics_line('END:VALARM')
                . ics_line('END:VEVENT');
        }
    }
    return $ics;
}

/* ---------- Sleep log (stored as 'sleep' health readings, alongside Apple Health nights) ---------- */

const SLEEP_QUALITY = ['Slept well', 'OK', 'Restless', 'Woke often', 'Poor'];

/** 7.5 -> "7h 30m" */
function format_hours(float $hours): string
{
    $minutes = (int) round($hours * 60);
    return intdiv($minutes, 60) . 'h' . ($minutes % 60 ? ' ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm' : '');
}

/**
 * Check the sleep log form: the night's date, bed and wake times, and how it went.
 * @return array{0: ?array, 1: string[]} [reading to store, errors]
 */
function sleep_log_from_post(DateTimeImmutable $today): array
{
    $errors = [];
    $night = parse_date(post('night'));
    $bed = post('bed_time');
    $wake = post('wake_time');
    if (!$night || $night > $today) {
        $errors[] = 'Choose the night you are logging.';
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $bed) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $wake)) {
        $errors[] = 'Enter when you went to bed and when you got up.';
    }
    if ($errors) {
        return [null, $errors];
    }
    // A bedtime before about 3pm is the early hours of the next day.
    $bedAt = new DateTimeImmutable($night->format('Y-m-d') . ' ' . substr($bed, 0, 5), $today->getTimezone());
    if ((int) substr($bed, 0, 2) < 15) {
        $bedAt = $bedAt->modify('+1 day');
    }
    $wakeAt = new DateTimeImmutable($bedAt->format('Y-m-d') . ' ' . substr($wake, 0, 5), $today->getTimezone());
    if ($wakeAt <= $bedAt) {
        $wakeAt = $wakeAt->modify('+1 day');
    }
    $hours = ($wakeAt->getTimestamp() - $bedAt->getTimestamp()) / 3600;
    $awake = post('awake_minutes') === '' ? 0 : (int) post('awake_minutes');
    if ($awake < 0 || $awake >= $hours * 60) {
        $errors[] = 'The time awake in the night must be less than the time in bed.';
    }
    if ($hours > 16) {
        $errors[] = 'That is more than 16 hours in bed. Check the times.';
    }
    if ($wakeAt->getTimestamp() > time() + 300) {
        $errors[] = 'The time you got up is in the future.';
    }
    $quality = in_array(post('quality'), SLEEP_QUALITY, true) ? post('quality') : null;
    $notes = trim('In bed ' . $bedAt->format('H:i') . ' to ' . $wakeAt->format('H:i')
        . ($awake ? ", about $awake min awake" : '') . '. ' . mb_substr(post('notes'), 0, 400));
    return [$errors ? null : [
        'hours'       => round($hours - $awake / 60, 2),
        'context'     => $quality,
        'notes'       => $notes,
        'recorded_at' => $wakeAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ], $errors];
}

/** Sleep readings (Pillbox and Apple Health) for nights ending on or after a local date, newest first. */
function sleep_nights(int $userId, DateTimeImmutable $fromDay): array
{
    return q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'sleep' AND recorded_at >= ? ORDER BY recorded_at DESC",
        [$userId, $fromDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')])->fetchAll();
}
