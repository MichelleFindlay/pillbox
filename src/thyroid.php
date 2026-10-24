<?php
declare(strict_types=1);

/*
 * Thyroid (a Care category, care.php?area=thyroid), for an underactive thyroid treated with
 * levothyroxine (sometimes liothyronine). Following NHS and NICE guidance:
 * - Medicine set-ups with timing advice (empty stomach, or bedtime), missed-dose guidance, the
 *   brand collected each time, and automatic spacing checks against calcium, iron, antacids
 *   and minerals across all of someone's medicines (spacing_clashes(), also shown on Meds).
 * - TSH and free T4 in Blood tests > Results and trends, with dose changes marked on the chart;
 *   when the next TSH test is due (about 3 months after a dose change until stable, then
 *   yearly); a biotin reminder before blood tests.
 * - A weekly check of symptoms that can mean under- or overtreatment, charted with dose changes.
 * - Prompts: pregnancy (tell the GP straight away), starting or changing oestrogen, the medical
 *   exemption certificate, a medical ID line, and a one-page clinician summary (thyroid_summary.php).
 */

const THYROID_PATTERN = '/\b(levothyrox\w*|thyroxin\w*|liothyronin\w*|eltroxin|levoxyl|euthyrox|synthroid|tirosint|cytomel|armour)\b/i';
// Taken within about 4 hours of levothyroxine, these reduce how much is absorbed.
const SPACING_PATTERN = '/\b(calcium|calcichew|adcal|ferrous|iron|ferric|antacid|gaviscon|rennie|peptac|magnesium|zinc|multivitamin\w*|multi-?vitamin\w*|aluminium|sucralfate|colestyramine|cholestyramine|sevelamer|lanthanum|orlistat)\b/i';
const SPACING_HOURS = 4;

const THYROID_PRESETS = [
    'levothyroxine'       => ['label' => 'Levothyroxine (morning)', 'name' => 'Levothyroxine', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '07:00',
        'instructions' => 'On an empty stomach with water, 30 to 60 minutes before breakfast or caffeine. Keep 4 hours apart from calcium, iron, antacids and multivitamins with minerals'],
    'levothyroxine_night' => ['label' => 'Levothyroxine (bedtime)', 'name' => 'Levothyroxine', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '22:30',
        'instructions' => 'At bedtime, at least 3 hours after your last meal. Keep 4 hours apart from calcium, iron, antacids and multivitamins with minerals'],
    'liothyronine'        => ['label' => 'Liothyronine (T3)', 'name' => 'Liothyronine', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '07:00',
        'instructions' => 'As prescribed, usually alongside levothyroxine'],
];

const THYROID_UNDER = ['tired' => 'Tiredness, low energy', 'cold' => 'Feeling cold', 'weight_gain' => 'Weight gain', 'constipation' => 'Constipation',
    'skin_hair' => 'Dry skin or thinning hair', 'mood_fog' => 'Low mood or brain fog', 'aches' => 'Muscle aches', 'heavy_periods' => 'Heavier periods'];
const THYROID_OVER = ['palpitations' => 'Palpitations, fast heartbeat', 'anxiety' => 'Anxiety or shakiness', 'sleep' => 'Trouble sleeping',
    'hot' => 'Feeling hot or sweating', 'weight_loss' => 'Weight loss', 'diarrhoea' => 'Diarrhoea'];
const THYROID_SEVERITY = [0 => 'No', 1 => 'A little', 2 => 'Quite a bit', 3 => 'A lot'];

function thyroid_meds(array $meds): array
{
    return array_values(array_filter($meds, fn ($m) => preg_match(THYROID_PATTERN, $m['name'] . ' ' . $m['reason'])));
}

/** Thyroid medicine changes as chart markers: [[unix time, short label], ...]. */
function thyroid_dose_markers(int $userId): array
{
    return medication_change_markers($userId, fn (string $text) => (bool) preg_match(THYROID_PATTERN, $text));
}

/**
 * Doses closer than 4 hours to a thyroid medicine, across all of someone's regular medicines:
 * [[thyroid med, time, other med, time, hours apart], ...], plus as-needed ones to keep apart.
 */
function spacing_clashes(array $meds, array $schedulesByMed): array
{
    $thyroid = thyroid_meds($meds);
    $others = array_filter($meds, fn ($m) => preg_match(SPACING_PATTERN, $m['name'] . ' ' . $m['reason'] . ' ' . $m['instructions']) && !preg_match(THYROID_PATTERN, $m['name']));
    $out = ['clashes' => [], 'as_needed' => []];
    $minutes = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
    foreach ($thyroid as $t) {
        foreach ($others as $o) {
            if ($o['as_needed']) {
                $out['as_needed'][$o['id']] = $o;
                continue;
            }
            foreach ($schedulesByMed[$t['id']] ?? [] as $a) {
                foreach ($schedulesByMed[$o['id']] ?? [] as $b) {
                    $gap = abs($minutes($a['dose_time']) - $minutes($b['dose_time']));
                    $gap = min($gap, 1440 - $gap);
                    if ($gap < SPACING_HOURS * 60) {
                        $out['clashes'][] = [$t, substr($a['dose_time'], 0, 5), $o, substr($b['dose_time'], 0, 5), $gap / 60];
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * When the next TSH test is due (NICE): about 3 months after starting or changing the dose, every
 * 3 months until two results in a row are steady, then once a year. @return array{0: ?DateTimeImmutable, 1: string}
 */
function next_tsh_due(int $userId): array
{
    $change = null;
    foreach (thyroid_dose_markers($userId) as [$ts]) {
        $change = $ts;
    }
    $tsh = q("SELECT taken_at FROM lab_results WHERE user_id = ? AND test = 'tsh' ORDER BY taken_at", [$userId])->fetchAll(PDO::FETCH_COLUMN);
    $since = $change ? array_values(array_filter($tsh, fn ($t) => strtotime($t . ' UTC') > $change)) : $tsh;
    if ($change && !$since) {
        return [(new DateTimeImmutable('@' . $change))->modify('+3 months'), 'about 3 months after your last dose change'];
    }
    if (!$tsh) {
        return [null, ''];
    }
    $last = new DateTimeImmutable(end($tsh) . ' UTC');
    return $change && count($since) < 2
        ? [$last->modify('+3 months'), 'every 3 months until your dose is settled']
        : [$last->modify('+12 months'), 'once a year while your dose is steady'];
}

/** Oestrogen started or changed recently (HRT, gender-affirming or the pill): worth a thyroid check. */
function recent_oestrogen_change(int $userId): ?array
{
    $row = q("SELECT c.effective_date, m.name FROM medication_changes c JOIN medications m ON m.id = c.medication_id
              WHERE c.user_id = ? AND c.kind IN ('started', 'increased', 'decreased', 'strength', 'restarted') AND c.effective_date >= ?" . private_med_filter() . "
                AND (m.name REGEXP 'oestr|estrad|estrogen|combined|HRT' OR m.reason REGEXP 'HRT|oestrogen|Gender-affirming')
              ORDER BY c.effective_date DESC LIMIT 1", [$userId, local_today()->modify('-4 months')->format('Y-m-d')])->fetch();
    return $row ?: null;
}

function thyroid_weeks(int $userId, int $limit = 26): array
{
    $rows = q('SELECT * FROM thyroid_weeks WHERE user_id = ? ORDER BY week_start DESC LIMIT ' . $limit, [$userId])->fetchAll();
    foreach ($rows as &$r) {
        $r['scores'] = array_map('intval', json_decode((string) $r['scores'], true) ?: []);
        $r['under'] = array_sum(array_intersect_key($r['scores'], THYROID_UNDER));
        $r['over'] = array_sum(array_intersect_key($r['scores'], THYROID_OVER));
    }
    return array_reverse($rows);
}

/** The line for a phone's medical ID / ICE details. */
function thyroid_medical_id(array $meds): string
{
    $parts = array_map(fn ($m) => trim($m['name'] . ' ' . $m['strength']), thyroid_meds($meds));
    return 'Underactive thyroid (hypothyroidism)' . ($parts ? '. Takes ' . implode(' and ', $parts) . ' daily.' : '.');
}

/** Actions posted from care.php?area=thyroid ('thy_...'); always redirects. */
function thyroid_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    if (post('action') === 'thy_week') {
        $scores = [];
        foreach (THYROID_UNDER + THYROID_OVER as $k => $_) {
            if (isset($_POST['s'][$k]) && $_POST['s'][$k] !== '') {
                $scores[$k] = max(0, min(3, (int) $_POST['s'][$k]));
            }
        }
        $monday = $today->modify('monday this week')->format('Y-m-d');
        q('INSERT INTO thyroid_weeks (user_id, week_start, scores, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE scores = VALUES(scores), notes = VALUES(notes)',
            [$uid, $monday, json_encode($scores), trim(mb_substr(post('notes'), 0, 500)) ?: null]);
        flash('success', 'Saved for this week.');
    }
    redirect('care.php?area=thyroid#thy-symptoms');
}

/** The thyroid sections at the top of care.php?area=thyroid. */
function thyroid_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $meds = user_medications($uid);
    $schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
    $thyroid = thyroid_meds($meds);
    $spacing = spacing_clashes($meds, $schedules);
    [$tshDue, $tshWhy] = next_tsh_due($uid);
    $latest = [];
    foreach (q("SELECT * FROM lab_results WHERE user_id = ? AND test IN ('tsh', 'free_t4') ORDER BY taken_at", [$uid]) as $r) {
        $latest[$r['test']] = $r;
    }
    $weeks = thyroid_weeks($uid);
    $thisWeek = array_values(array_filter($weeks, fn ($w) => $w['week_start'] === $today->modify('monday this week')->format('Y-m-d')))[0] ?? null;
    $oestrogen = $thyroid ? recent_oestrogen_change($uid) : null;
    $pregnant = in_array('pregnancy', user_care_areas($user), true) && active_pregnancy($uid);
    ?>
<?php if ($thyroid && $pregnant): ?>
<p class="flash flash-warning">You're pregnant and take thyroid medicine: if you haven't already, contact your GP now. The dose usually needs increasing early in pregnancy, and TSH is checked more often.</p>
<?php endif; ?>
<?php if ($oestrogen): ?>
<p class="flash flash-info">You started or changed <?= h($oestrogen['name']) ?> on <?= h(date('j M', strtotime($oestrogen['effective_date']))) ?>. Oestrogen can change how much levothyroxine you need, so ask your GP whether to check your TSH in 6 to 8 weeks.</p>
<?php endif; ?>
<section class="card" id="thy-meds">
    <div class="card-head">
        <h2>Your thyroid medicine <?= help_link('thyroid', '', 'thyroid') ?></h2>
        <a class="btn btn-small" href="<?= h(url('thyroid_summary.php')) ?>">Clinician summary</a>
    </div>
    <?php foreach ($thyroid as $m): $ms = $schedules[$m['id']] ?? []; ?>
    <p><?= med_dot($m['colour']) ?> <strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?> <span class="muted small"><?= h(medication_regimen($m, $ms)) ?><?= $m['brand'] ? ' · ' . h($m['brand']) : '' ?></span></p>
    <?php endforeach; ?>
    <?php if (!$thyroid && share_can('doses', 'edit')): ?>
    <p class="small">Add yours (opens the medication form filled in, to set your dose):</p>
    <div class="preset-list"><?php foreach (THYROID_PRESETS as $key => $p): ?><a class="btn btn-small" href="<?= h(url('medication_edit.php?preset=' . $key)) ?>">+ <?= h($p['label']) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
    <ul class="small">
        <li>Take it on an empty stomach with water, 30 to 60 minutes before breakfast or caffeine, or at bedtime at least 3 hours after eating. The same time each day matters most.</li>
        <li>Missed a dose? Take it when you remember, unless your next dose is nearly due. Never take two doses together.</li>
        <li>Keep it about 4 hours apart from calcium, iron, antacids and multivitamins with minerals.</li>
        <li>Note the brand when you collect it: some people notice a difference when it changes.</li>
    </ul>
    <?php foreach ($spacing['clashes'] as [$t, $tTime, $o, $oTime, $gap]): ?>
    <p class="small warn">&#9888; <?= h($t['name']) ?> at <?= h($tTime) ?> and <?= h($o['name']) ?> at <?= h($oTime) ?> are only <?= h(num(round($gap, 1))) ?> hours apart. Try to keep them about 4 hours apart (change the times on the Meds page, or ask your pharmacist).</p>
    <?php endforeach; ?>
    <?php foreach ($spacing['as_needed'] as $o): ?>
    <p class="small">When you take <?= h($o['name']) ?>, keep it about 4 hours away from your thyroid medicine.</p>
    <?php endforeach; ?>
</section>

<section class="card" id="thy-bloods">
    <h2>Blood tests</h2>
    <div class="tiles">
        <?php foreach (['tsh' => 'TSH', 'free_t4' => 'Free T4'] as $k => $l): if (isset($latest[$k])): ?>
        <div class="tile"><span class="tile-label">Latest <?= $l ?></span><span class="tile-value"><?= h(num($latest[$k]['value'])) ?></span>
            <span class="tile-meta"><?= h($latest[$k]['unit']) ?> · <?= h(local_time($latest[$k]['taken_at'], 'j M Y')) ?></span></div>
        <?php endif; endforeach; ?>
        <?php if ($tshDue): ?>
        <div class="tile"><span class="tile-label">Next TSH test</span><span class="tile-value small-value"><?= h($tshDue->format('M Y')) ?></span><span class="tile-meta"><?= h($tshWhy) ?></span></div>
        <?php endif; ?>
    </div>
    <p class="small">Log results, set your clinic's target range and see the trend (with dose changes marked) in <a href="<?= h(url('care.php?area=bloods#lab-tsh')) ?>">Blood tests</a>.</p>
    <p class="small"><strong>Before a blood test:</strong> if you take biotin (often in hair, skin and nail vitamins), stop it for 2 days beforehand, as it can upset thyroid results.
        Take your thyroid medicine at your usual time unless your clinic says otherwise.</p>
</section>

<section class="card" id="thy-symptoms">
    <h2>This week</h2>
    <p class="muted small">Changes are slow, so once a week is enough. These can be signs that your dose needs looking at (or something else): take the trend to your GP.</p>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $thisWeek ? '' : ' open' ?>><summary><?= $thisWeek ? 'Update this week' : 'How has this week been?' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="thy_week">
            <div class="thy-cols">
                <?php foreach (['Could mean too little' => THYROID_UNDER, 'Could mean too much' => THYROID_OVER] as $title => $items): ?>
                <fieldset><legend><?= h($title) ?></legend>
                    <?php foreach ($items as $k => $l): $v = $thisWeek['scores'][$k] ?? null; ?>
                    <label class="thy-row"><span><?= h($l) ?></span><select name="s[<?= $k ?>]"><option value="">&ndash;</option><?php foreach (THYROID_SEVERITY as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected($v === $sk) ?>><?= h($sl) ?></option><?php endforeach; ?></select></label>
                    <?php endforeach; ?>
                </fieldset>
                <?php endforeach; ?>
            </div>
            <label>Notes <input name="notes" maxlength="500" value="<?= h((string) ($thisWeek['notes'] ?? '')) ?>"></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
    <?php if (count($weeks) > 1): $pt = fn (string $k) => array_map(fn ($w) => [strtotime($w['week_start'] . ' 12:00 UTC'), (float) $w[$k]], $weeks); ?>
    <?= svg_line_chart([['label' => 'Too little (score)', 'unit' => '', 'points' => $pt('under')], ['label' => 'Too much (score)', 'unit' => '', 'points' => $pt('over')]],
        strtotime($weeks[0]['week_start'] . ' UTC') - 86400 * 3, strtotime(end($weeks)['week_start'] . ' UTC') + 86400 * 4, 'Weekly symptom scores', [], thyroid_dose_markers($uid)) ?>
    <p class="muted small">Dashed lines: dose changes.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Good to know</h2>
    <ul class="small">
        <li>In England, people on thyroid hormone replacement can get <strong>free prescriptions</strong> with a medical exemption certificate (MedEx): ask your GP for form FP92A.
            <?php if ($canEdit): ?><form method="post" action="<?= h(url('care.php?area=prescriptions')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="check_preset"><input type="hidden" name="kind" value="medex">
                <button class="btn-link small" type="submit">Track its renewal date</button></form><?php endif; ?></li>
        <li>Planning a pregnancy, or just found out you're pregnant? Talk to your GP straight away: the dose usually needs to go up early on.</li>
        <li>Starting or changing oestrogen (HRT, the pill or gender-affirming hormones) can change how much you need: ask about a TSH check.</li>
        <li>Low mood can sometimes be thyroid-related: mention it at your review.</li>
    </ul>
    <?php if ($thyroid): ?>
    <details class="q-edit"><summary>For your phone's medical ID</summary>
        <textarea rows="2" readonly data-select-on-focus aria-label="Medical ID text"><?= h(thyroid_medical_id($meds)) ?></textarea>
    </details>
    <?php endif; ?>
</section>
    <?php
}
