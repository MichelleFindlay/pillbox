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
            'guard_clean' => ['label' => 'Clean night guard', 'times' => ['07:30'], 'tip' => 'Rinse and brush it gently with cool water, then let it dry in its case.'],
        ],
        'checks' => [
            'dental_checkup' => ['label' => 'Dental check-up', 'interval' => 365, 'tip' => 'Change how often to match what your dentist recommends.'],
            'night_guard'    => ['label' => 'Night guard check (at your dental visit)', 'interval' => 365,
                'tip' => 'Take your night guard or bite splint to your check-up so your dentist can check the fit and wear. Set it to match your check-ups.'],
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
            'eye_drops'         => ['label' => 'Eye drops (once opened)', 'track' => 'expiry', 'interval' => 28,
                'tip' => 'Most eye drops must be thrown away 28 days after opening. Some single-dose or preservative-free ones differ: check the leaflet.'],
            'eye_test'          => ['label' => 'Eye test', 'interval' => 730, 'tip' => 'The NHS recommends one at least every 2 years, or more often if your optician advises it.'],
            'diabetic_eye'      => ['label' => 'Diabetic eye screening', 'interval' => 365, 'tip' => 'Every 1 to 2 years if you have diabetes, as your screening service advises.'],
        ],
    ],
    'sleep' => [
        'title' => 'Sleep', 'short' => 'Sleep', 'icon' => '&#x1F319;', 'default' => true, 'appointment' => null,
        'intro' => 'Most adults need 7 to 9 hours a night. Going to bed and getting up at about the same time every day helps most.',
        'help'  => 'See a GP if poor sleep has lasted for months or is affecting your day, or if someone notices you stop breathing, '
                 . 'gasp or choke in your sleep (possible sleep apnoea). If you use CPAP, your sleep service can help with mask fit and supplies; '
                 . 'replacing CPAP parts is under Respiratory.',
        'presets' => [
            'wind_down'     => ['label' => 'Start winding down', 'times' => ['22:00'], 'remind' => true, 'tip' => 'Screens off and lights low. Something relaxing: a bath, reading, or gentle stretches.'],
            'bedtime'       => ['label' => 'Bedtime', 'times' => ['22:30'], 'remind' => true, 'tip' => 'About the same time every night, weekends too.'],
            'cpap_mask'     => ['label' => 'Clean CPAP mask', 'times' => ['08:00'], 'tip' => 'Wipe or wash the cushion with warm soapy water and let it air-dry.'],
            'cpap_water'    => ['label' => 'Empty CPAP humidifier', 'times' => ['08:00'], 'tip' => 'Empty it and let it dry, then refill with fresh water at night (distilled if your supplier says so).'],
            'cpap_tubing'   => ['label' => 'Wash CPAP tubing', 'times' => ['09:00'], 'every' => 7, 'tip' => 'Warm soapy water, rinse well, then hang it up to dry.'],
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
    'respiratory' => [
        'title' => 'Respiratory', 'short' => 'Breathing', 'icon' => '&#x1FAC1;', 'default' => false, 'appointment' => 'nurse',
        'intro' => 'Inhalers, spacers, peak flow meters, CPAP and nebuliser parts, each replaced on its own schedule.',
        'help'  => 'Call 999 if you are struggling to breathe, your reliever is not helping, or your lips or fingertips are turning blue or grey. '
                 . 'Have an asthma or COPD review at least once a year.',
        'presets' => [
            'peak_flow'  => ['label' => 'Peak flow', 'times' => ['08:00', '20:00'], 'tip' => 'Best of 3 blows, before your inhaler. Record it under Readings.'],
            'neb_clean'  => ['label' => 'Clean nebuliser', 'times' => ['21:00'], 'tip' => 'Wash the chamber and mouthpiece or mask after use, rinse and air-dry.'],
            'spacer_wash' => ['label' => 'Wash spacer', 'times' => ['09:00'], 'every' => 30, 'tip' => 'Warm soapy water, then let it drip-dry without rinsing or wiping (stops static).'],
        ],
        'checks' => [
            'review'       => ['label' => 'Asthma or COPD review', 'interval' => 365, 'tip' => 'At least yearly, with your GP or practice nurse. Take your inhalers and spacer.'],
            'spacer'       => ['label' => 'Spacer replacement', 'interval' => 365, 'tip' => 'Roughly yearly, or sooner if it is cracked or the valve sticks.'],
            'peak_meter'   => ['label' => 'Peak flow meter replacement', 'interval' => 365, 'tip' => 'Replace as the manufacturer advises, often every 1 to 2 years.'],
            'cpap_cushion' => ['label' => 'CPAP mask cushion', 'interval' => 30, 'tip' => 'Often monthly, or sooner if it stops sealing. Your sleep service can tell you how to order parts.'],
            'cpap_filter'  => ['label' => 'CPAP filter', 'interval' => 28, 'tip' => "Disposable filters are often changed every 2 to 4 weeks. Follow your machine's guidance."],
            'cpap_tubing'  => ['label' => 'CPAP tubing', 'interval' => 91, 'tip' => 'Often every 3 to 6 months, or sooner if it is damaged.'],
            'cpap_mask'    => ['label' => 'CPAP mask frame and headgear', 'interval' => 182, 'tip' => 'Often every 6 months, or when the straps lose their stretch.'],
            'cpap_water'   => ['label' => 'CPAP humidifier chamber', 'interval' => 182, 'tip' => 'Often every 6 months, or if it is cloudy or cracked.'],
            'neb_parts'    => ['label' => 'Nebuliser chamber and mask', 'interval' => 91, 'tip' => "Follow the manufacturer's guidance: often every 3 months."],
            'neb_filter'   => ['label' => 'Nebuliser compressor filter', 'interval' => 182, 'tip' => 'When it looks grey, or as the manual says.'],
        ],
    ],
    'diabetes' => [
        'title' => 'Diabetes and monitoring', 'short' => 'Monitoring', 'icon' => '&#x1FA78;', 'default' => false, 'appointment' => 'nurse',
        'intro' => 'Keep your meters accurate and your supplies stocked: test strips, lancets, sensors and batteries.',
        'help'  => 'If your readings are very high or low and you feel unwell, follow your sick-day rules or hypo plan and contact your diabetes team or NHS 111.',
        'presets' => [
            'cgm'    => ['label' => 'Change CGM sensor', 'times' => ['09:00'], 'every' => 14, 'tip' => 'Set how often to match your sensor: often every 10 to 15 days.'],
            'lancet' => ['label' => 'New lancet', 'times' => ['08:00'], 'every' => 7, 'tip' => 'A fresh lancet hurts less. Use each one once if you can.'],
        ],
        'checks' => [
            'control'   => ['label' => 'Glucose meter control solution test', 'interval' => 30,
                'tip' => "Also when you open a new pot of strips, or if the meter has been dropped. Note the control solution's own expiry once opened."],
            'strips'    => ['label' => 'Test strips', 'track' => 'stock', 'unit' => 'strips', 'low' => 50, 'tip' => 'Order a repeat when you get down to about 2 weeks of strips.'],
            'strip_exp' => ['label' => 'Test strip expiry', 'track' => 'expiry', 'tip' => 'Check the pot: some strips also expire a few months after opening.'],
            'lancets'   => ['label' => 'Lancets', 'track' => 'stock', 'unit' => 'lancets', 'low' => 20],
            'sensors'   => ['label' => 'CGM sensors', 'track' => 'stock', 'unit' => 'sensors', 'low' => 2, 'tip' => 'Order more when you are down to your last two.'],
            'bp_monitor' => ['label' => 'Blood pressure monitor check and batteries', 'interval' => 365,
                'tip' => 'Use a validated monitor. Once a year, change the batteries and compare it with a reading at your GP surgery or pharmacy.'],
            'thermometer' => ['label' => 'Thermometer batteries', 'interval' => 365],
            'hba1c'     => ['label' => 'Diabetes review (HbA1c)', 'interval' => 365, 'results' => true, 'tip' => 'At least yearly; often more if your treatment is changing.'],
        ],
    ],
    'bloods' => [
        'title' => 'Blood tests', 'short' => 'Bloods', 'icon' => '&#x1F9EA;', 'default' => false, 'appointment' => 'blood_test',
        'intro' => 'Some medicines need regular blood tests to stay safe. Keep track of when they are due, and what the results were.',
        'help'  => 'Your prescriber decides how often you need each test. If you are unsure whether a test is still needed, ask before stopping it.',
        'presets' => [],
        'checks' => [
            'lithium'      => ['label' => 'Lithium level', 'interval' => 91, 'results' => true, 'tip' => 'Usually every 3 months once settled (more often at first). Taken about 12 hours after your last dose.'],
            'methotrexate' => ['label' => 'Methotrexate blood tests', 'interval' => 91, 'results' => true, 'tip' => 'Full blood count, liver and kidney function: often every 2 to 3 months once stable.'],
            'inr'          => ['label' => 'INR (warfarin)', 'interval' => 42, 'results' => true, 'tip' => 'Your anticoagulation clinic sets how often: anything from weekly to every 12 weeks.'],
            'thyroid'      => ['label' => 'Thyroid function', 'interval' => 365, 'results' => true, 'tip' => 'Usually yearly on levothyroxine, and 6 to 8 weeks after a dose change.'],
            'kidney'       => ['label' => 'Kidney function (U&Es)', 'interval' => 365, 'results' => true, 'tip' => 'e.g. on ACE inhibitors, diuretics or metformin; often yearly.'],
            'lipids'       => ['label' => 'Cholesterol', 'interval' => 365, 'results' => true],
            'fbc'          => ['label' => 'Full blood count', 'interval' => 365, 'results' => true],
        ],
    ],
    'prescriptions' => [
        'title' => 'Prescriptions and payments', 'short' => 'Payments', 'icon' => '&#x1F4B7;', 'default' => false, 'appointment' => null,
        'intro' => 'Prepayment and exemption certificates, and what you pay for prescriptions. (Prescriptions are free in Scotland, Wales and Northern Ireland.)',
        'help'  => 'In England prescriptions are free if you are 60 or over, under 16, 16 to 18 and in full-time education, or have a valid exemption certificate. '
                 . 'The eligibility checker on nhs.uk tells you if you qualify.',
        'presets' => [],
        'checks' => [
            'ppc'     => ['label' => 'Prescription Prepayment Certificate (PPC)', 'track' => 'expiry', 'lengths' => [3, 12],
                'tip' => 'Covers all your NHS prescriptions. Renew before it runs out (it can renew itself by Direct Debit).'],
            'hrt_ppc' => ['label' => 'HRT Prepayment Certificate', 'track' => 'expiry', 'lengths' => [12],
                'tip' => 'Covers HRT medicines on the HRT list only, for 12 months.'],
            'medex'   => ['label' => 'Medical exemption certificate (MedEx)', 'track' => 'expiry',
                'tip' => 'Usually lasts 5 years, or until you turn 60. You should be sent a renewal form; if not, ask your GP surgery.'],
            'matex'   => ['label' => 'Maternity exemption certificate (MatEx)', 'track' => 'expiry',
                'tip' => "Lasts until 12 months after your due date (or your baby's birth, if you applied after)."],
            'hc2'     => ['label' => 'HC2 certificate (NHS Low Income Scheme)', 'track' => 'expiry',
                'tip' => 'Valid for the dates printed on it. Reapply about 4 weeks before it ends.'],
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
            'charge'      => ['label' => 'Put hearing aids on charge', 'times' => ['22:00'], 'tip' => 'For rechargeable aids: in the charger overnight.'],
            'ear_protect' => ['label' => 'Ear protection', 'times' => ['08:00'], 'tip' => 'Earplugs or defenders for loud work, concerts or power tools.'],
        ],
        'checks' => [
            'hearing_test' => ['label' => 'Hearing test', 'interval' => 1095, 'tip' => 'Every few years; every year if you work around loud noise.'],
            'aid_service'  => ['label' => 'Hearing aid service', 'interval' => 365, 'tip' => 'New tubes, filters and a check of the settings.'],
            'domes'        => ['label' => 'Change domes', 'interval' => 61, 'tip' => 'The soft tips: usually every 1 to 3 months, or when they go stiff or discoloured.'],
            'wax_filters'  => ['label' => 'Change wax filters', 'interval' => 30, 'tip' => 'Monthly, or when the sound goes quiet or muffled.'],
            'audiology'    => ['label' => 'Audiology review', 'interval' => 1095, 'tip' => 'A reassessment of your hearing and aids, usually every few years. Ask sooner if your hearing changes.'],
        ],
    ],
    'skin' => [
        'title' => 'Skin and wound care', 'short' => 'Skin', 'icon' => '&#x1F9F4;', 'default' => true, 'appointment' => null,
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
            'dressings'  => ['label' => 'Dressings', 'track' => 'stock', 'unit' => 'dressings', 'low' => 5, 'tip' => 'Restock before you run out: ask your GP surgery or nurse for a repeat if they are prescribed.'],
            'sunscreen'  => ['label' => 'Sunscreen (once opened)', 'track' => 'expiry', 'interval' => 365, 'tip' => 'Most last about 12 months once opened: look for the open-jar symbol, e.g. 12M.'],
            'emollient'  => ['label' => 'Emollient', 'track' => 'stock', 'unit' => 'tubs or tubes', 'low' => 1, 'tip' => 'Keep a spare. Check the use-by after opening on pump dispensers.'],
        ],
    ],
    'mobility' => [
        'title' => 'Mobility and supports', 'short' => 'Mobility', 'icon' => '&#x1F9AF;', 'default' => false, 'appointment' => 'physio',
        'intro' => 'Braces, supports, compression stockings, walking aids and insoles all wear out, often before you notice.',
        'help'  => 'Ask your GP, physio or orthotics service if a support stops fitting, rubs, or your needs change.',
        'presets' => [
            'stockings_on' => ['label' => 'Compression stockings on', 'times' => ['08:00'], 'tip' => 'Put them on first thing, before your legs swell.'],
            'stockings_wash' => ['label' => 'Wash compression stockings', 'times' => ['20:00'], 'every' => 2, 'tip' => 'Hand-warm wash and dry flat, away from direct heat.'],
        ],
        'checks' => [
            'stockings' => ['label' => 'Compression stockings replacement', 'interval' => 91, 'tip' => 'Usually every 3 to 6 months, as they lose their compression.'],
            'brace'     => ['label' => 'Brace or support replacement', 'interval' => 182, 'tip' => 'Roughly every 3 to 6 months with daily use, or when the straps or padding wear.'],
            'ferrules'  => ['label' => 'Walking aid ferrules and tips', 'interval' => 91, 'tip' => 'Check the rubber tips: replace when the tread is worn smooth or the metal shows through.'],
            'orthotics' => ['label' => 'Orthotics or insoles', 'interval' => 365, 'tip' => 'Often replaced yearly, or when they flatten, crack or start to hurt.'],
            'wheelchair' => ['label' => 'Wheelchair or scooter service', 'interval' => 365],
        ],
    ],
    'emergency' => [
        'title' => 'Emergency and first aid', 'short' => 'First aid', 'icon' => '&#x26D1;&#xFE0F;', 'default' => true, 'appointment' => null,
        'intro' => 'Emergency medicines that must be in date, a first aid kit that is actually stocked, and home safety checks.',
        'help'  => 'In an emergency call 999. After using an adrenaline auto-injector, always call 999 and say "anaphylaxis", even if you feel better.',
        'presets' => [],
        'checks' => [
            'adrenaline' => ['label' => 'Adrenaline auto-injector expiry', 'track' => 'expiry',
                'tip' => "Carry two. Register them with the manufacturer's expiry alert service as well, if there is one."],
            'adrenaline2' => ['label' => 'Second adrenaline auto-injector expiry', 'track' => 'expiry'],
            'first_aid'  => ['label' => 'First aid kit', 'track' => 'kit', 'interval' => 182,
                'tip' => 'Check it every 6 months: restock what has been used and replace anything out of date.',
                'items' => "Plasters\nSterile dressings\nBandages\nMedical tape\nDisposable gloves\nScissors\nTweezers\nAlcohol-free cleansing wipes\nThermometer\nPainkillers | \nAntihistamine | \nAntiseptic cream | \nEyewash | "],
            'meds_bag'   => ['label' => 'Emergency meds bag', 'track' => 'kit', 'interval' => 91,
                'tip' => 'For travel or work. Check it before trips, and every few months.',
                'items' => "Reliever inhaler | \nAdrenaline auto-injectors (2) | \nAntihistamine | \nGlucose tablets | \nSpare doses of regular medicines | \nMedication list\nEmergency contact card"],
            'smoke'      => ['label' => 'Test smoke alarms', 'interval' => 30, 'tip' => 'Press the test button monthly. Replace the alarm itself about every 10 years.'],
            'co_alarm'   => ['label' => 'Test carbon monoxide alarm', 'interval' => 30],
            'extinguisher' => ['label' => 'Fire extinguisher check', 'interval' => 365, 'tip' => 'Check the gauge is in the green and it is not damaged. Fire blankets too.'],
        ],
    ],
    'sharps' => [
        'title' => 'Sharps and disposal', 'short' => 'Sharps', 'icon' => '&#x1F5D1;&#xFE0F;', 'default' => false, 'appointment' => null,
        'intro' => 'Keep track of your sharps bin, collections and medicines to take back to a pharmacy.',
        'help'  => 'Never put needles or sharps bins in household waste. Your council or pharmacy can tell you how collections work where you live.',
        'presets' => [],
        'checks' => [
            'sharps_bin' => ['label' => 'Sharps bin', 'track' => 'level', 'tip' => 'Close and lock it at the fill line, then arrange collection. Note how collection works in the notes.'],
            'collection' => ['label' => 'Sharps collection', 'interval' => 28, 'tip' => 'If you have a regular collection, set how often.'],
            'returns'    => ['label' => 'Take unused medicines to a pharmacy', 'interval' => 182,
                'tip' => "Out-of-date or unused medicines: any pharmacy will take them back. Don't put them in the bin or down the toilet."],
        ],
    ],
    'screening' => [
        'title' => 'Screening', 'short' => 'Screening', 'icon' => '&#x1F50E;', 'default' => true, 'appointment' => null,
        'intro' => 'NHS screening programmes, with the next due date worked out from your date of birth and when you were last screened.',
        'help'  => "Don't wait for screening if you have symptoms: see a GP. Intervals are for England; Scotland, Wales and Northern Ireland differ slightly.",
        'presets' => [],
        'checks' => [], // generated from the profile: see SCREENING_PROGRAMMES
    ],
    // Mind's habits and routines: run by the same code, but shown in the Mind tab, not Care.
    'mind' => [
        'title' => 'Habits & routines', 'short' => 'Habits', 'icon' => '&#x1F331;', 'default' => false, 'hidden' => true, 'appointment' => 'mental_health',
        'intro' => 'Small, regular routines can make the days feel steadier. Start with one or two.',
        'help'  => 'If you are struggling to keep up with the basics, that is worth mentioning to your GP or care team.',
        'presets' => [
            'morning'  => ['label' => 'Morning routine', 'times' => ['08:00'], 'tip' => 'e.g. get up, open the curtains, wash, get dressed, breakfast.'],
            'evening'  => ['label' => 'Evening wind-down', 'times' => ['21:30'], 'tip' => 'Lights down, something calm, same time each night.'],
            'screens'  => ['label' => 'Phone away', 'times' => ['22:00'], 'remind' => true, 'tip' => 'A screen-time limit: phone out of the bedroom, or on charge in another room.'],
            'outside'  => ['label' => 'Get outside', 'times' => ['12:00'], 'amount' => '15 minutes', 'tip' => 'Daylight helps mood and sleep.'],
            'meals'    => ['label' => 'Proper meal', 'times' => ['13:00', '18:30'], 'tip' => 'Even something small and simple counts.'],
            'journal'  => ['label' => 'Journal or check in', 'times' => ['20:30'], 'amount' => '5 minutes', 'tip' => 'Write a few lines, or do a quick check-in.'],
        ],
        'checks' => [
            'mh_review' => ['label' => 'Mental health medication review', 'interval' => 182, 'tip' => 'Change how often to match what your GP or psychiatrist says.'],
        ],
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
        return array_values(array_filter(array_keys(array_filter(CARE_AREAS, fn ($a) => $a['default'])), fn ($k) => share_area_allowed($k, $user)));
    }
    $chosen = explode(',', (string) $user['care_areas']);
    return array_values(array_filter(array_keys(CARE_AREAS), fn ($k) => empty(CARE_AREAS[$k]['hidden']) && in_array($k, $chosen, true) && share_area_allowed($k, $user)));
}

/** Areas whose routines and checks go on Today and in the calendar feed: Care categories, plus Mind's habits. */
function routine_areas(array $user): array
{
    return [...user_care_areas($user), ...(mind_enabled($user) ? ['mind'] : [])];
}

/** Care categories a user can switch on (not Mind's habits). */
function care_categories(): array
{
    return array_filter(CARE_AREAS, fn ($a) => empty($a['hidden']));
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
