<?php
declare(strict_types=1);

/*
 * Allergies (a Care category, care.php?area=allergies).
 * - Allergies, intolerances and side effects: what to, the reaction, how severe, who confirmed it,
 *   and whether it's still current (e.g. after penicillin allergy testing). "No known allergies"
 *   can be recorded too, since that matters to a prescriber as much as a list.
 * - Medicine allergies are checked against Meds (by name, and by group for e.g. penicillins), with a
 *   warning on the Meds page and when a medicine is saved.
 * - Anaphylaxis: what to do (NHS), adrenaline auto-injectors (MHRA: carry two), expiry checks.
 * - Food: the 14 allergens, eating out, and a card to show restaurant staff (allergy_card.php).
 * - Hay fever: pollen counts for the next few days (Open-Meteo, Europe only), using the town set
 *   for weather (see weather.php).
 * - A line for a phone's medical ID, and allergies at the top of the doctor's report.
 */

const ALLERGY_CATEGORIES = ['medicine' => 'Medicine', 'food' => 'Food', 'insect' => 'Insect sting', 'latex' => 'Latex',
    'environment' => 'Pollen, dust, animals or mould', 'contact' => 'Something touching the skin', 'other' => 'Other'];
const ALLERGY_KINDS = ['allergy' => 'Allergy', 'intolerance' => 'Intolerance (not an allergy)', 'side_effect' => 'Side effect'];
const ALLERGY_REACTIONS = ['hives' => 'Rash or hives', 'itch' => 'Itching', 'swelling' => 'Swelling of lips, face or eyes', 'breathing' => 'Wheezing or difficulty breathing',
    'throat' => 'Tight throat or hoarse voice', 'faint' => 'Dizzy or faint', 'collapse' => 'Collapse', 'stomach' => 'Stomach pain, vomiting or diarrhoea',
    'nose' => 'Sneezing, runny or blocked nose', 'eyes' => 'Itchy, red or watery eyes', 'skin' => 'Blistering or peeling skin', 'other' => 'Something else'];
const ALLERGY_SEVERITY = ['mild' => 'Mild', 'moderate' => 'Moderate', 'severe' => 'Severe', 'anaphylaxis' => 'Anaphylaxis'];
const ALLERGY_CONFIRMED = ['self' => 'Noticed it myself', 'gp' => 'Diagnosed by a doctor', 'clinic' => 'Confirmed by an allergy clinic', 'unsure' => 'Not sure'];

// The 14 allergens UK food businesses must tell you about.
const FOOD_ALLERGENS = ['Celery', 'Cereals containing gluten (wheat, rye, barley, oats)', 'Crustaceans (prawns, crab, lobster)', 'Eggs', 'Fish', 'Lupin', 'Milk',
    'Molluscs (mussels, oysters, squid)', 'Mustard', 'Peanuts', 'Sesame', 'Soya', 'Sulphites (sulphur dioxide)', 'Tree nuts (almonds, hazelnuts, walnuts, cashews...)'];
const ALLERGY_PICKS = [
    'medicine' => ['Penicillin', 'Amoxicillin', 'Cephalosporins', 'Ibuprofen and other NSAIDs', 'Aspirin', 'Codeine', 'Morphine', 'Sulfonamides (e.g. co-trimoxazole)',
        'Trimethoprim', 'Nitrofurantoin', 'Erythromycin', 'Clarithromycin', 'Doxycycline', 'Ciprofloxacin', 'Local anaesthetic', 'Contrast dye (for scans)', 'Chlorhexidine'],
    'insect' => ['Bee stings', 'Wasp stings'],
    'environment' => ['Grass pollen', 'Tree pollen', 'Weed pollen', 'House dust mite', 'Cats', 'Dogs', 'Horses', 'Mould'],
    'contact' => ['Nickel', 'Fragrance', 'Hair dye (PPD)', 'Sticking plasters', 'Wound dressings', 'Lanolin'],
];

// Medicine groups: an allergy to one usually means avoiding the others (patterns match medicine names).
const DRUG_ALLERGY_CLASSES = [
    'penicillins' => ['Penicillins', '/\b(penicillin\w*|amoxicillin|amoxil|co-?amoxiclav|augmentin|flucloxacillin|floxapen|phenoxymethylpenicillin|benzylpenicillin|piperacillin|tazocin|pivmecillinam|selexid|temocillin|ampicillin|magnapen|co-?fluampicil)\b/i',
        'Cephalosporins (such as cefalexin) are related: most people with a penicillin allergy can take them, but your prescriber should decide.'],
    'cephalosporins' => ['Cephalosporins', '/\b(cef\w+|ceph\w+|keflex)\b/i', null],
    'nsaids' => ['Anti-inflammatory painkillers (NSAIDs)', '/\b(ibuprofen|nurofen|brufen|naproxen|diclofenac|voltarol|aspirin|mefenamic|ponstan|celecoxib|etoricoxib|arcoxia|indometacin|meloxicam|ketoprofen|piroxicam|dexketoprofen|flurbiprofen|nsaids?|anadin)\b/i',
        'Some cold and flu remedies and gels contain ibuprofen or aspirin: check the label or ask a pharmacist.'],
    'opioids' => ['Opioids', '/\b(codeine|co-?codamol|dihydrocodeine|co-?dydramol|morphine|oxycodone|tramadol|tapentadol|fentanyl|buprenorphine|pethidine|diamorphine|oramorph|zapain|solpadeine|migraleve)\b/i',
        'Feeling sick or constipated on opioids is common and usually a side effect, not an allergy.'],
    'sulfonamides' => ['Sulfonamide antibiotics', '/\b(sulfonamides?|co-?trimoxazole|septrin|sulfamethoxazole|sulfadiazine|sulfasalazine|salazopyrin)\b/i', null],
    'macrolides' => ['Macrolide antibiotics', '/\b(macrolides?|erythromycin|clarithromycin|klaricid|azithromycin|zithromax)\b/i', null],
    'tetracyclines' => ['Tetracyclines', '/\b(tetracyclines?|doxycycline|lymecycline|minocycline|oxytetracycline)\b/i', null],
    'quinolones' => ['Quinolone antibiotics', '/\b(quinolones?|ciprofloxacin|levofloxacin|ofloxacin|moxifloxacin)\b/i', null],
];

function allergy_profile(int $userId): array
{
    return q('SELECT * FROM allergy_profiles WHERE user_id = ?', [$userId])->fetch()
        ?: ['user_id' => $userId, 'none_known' => 0, 'none_known_on' => null, 'aai' => 0, 'aai_brand' => null, 'aai_where' => null];
}

/** @return array[] allergies, current ones first, most severe first */
function user_allergies(int $userId, bool $currentOnly = false): array
{
    $rows = q('SELECT * FROM allergies WHERE user_id = ?' . ($currentOnly ? " AND status = 'current'" : '') . ' ORDER BY status, created_at', [$userId])->fetchAll();
    $rank = array_flip(array_keys(ALLERGY_SEVERITY));
    usort($rows, fn ($a, $b) => [$a['status'], -($rank[$a['severity']] ?? -1)] <=> [$b['status'], -($rank[$b['severity']] ?? -1)]);
    foreach ($rows as &$r) {
        $r['reactions'] = $r['reactions'] ? explode(',', $r['reactions']) : [];
    }
    return $rows;
}

/** The medicine group an allergen belongs to, if any ("Amoxicillin" => penicillins). */
function drug_class_of(string $text): ?string
{
    foreach (DRUG_ALLERGY_CLASSES as $key => [$label, $pattern]) {
        if (preg_match($pattern, $text) || stripos($text, $label) !== false) {
            return $key;
        }
    }
    return null;
}

/** Could someone with anaphylaxis (or adrenaline pens) need the emergency card? */
function anaphylaxis_risk(array $allergies, array $profile): bool
{
    return (bool) $profile['aai'] || (bool) array_filter($allergies, fn ($a) => $a['status'] === 'current' && $a['severity'] === 'anaphylaxis');
}

/**
 * Current medicines that match a current medicine allergy (or noted side effect): by the allergen's
 * own name, or by its group when that was chosen.
 * @return array<int, array[]> medication id => matching allergies
 */
function allergy_conflicts(array $allergies, array $meds): array
{
    $found = [];
    foreach ($allergies as $a) {
        if ($a['status'] !== 'current' || $a['category'] !== 'medicine') {
            continue;
        }
        $words = array_filter(preg_split('/[^a-z0-9-]+/i', mb_strtolower($a['allergen'])), fn ($w) => mb_strlen($w) >= 5 && !in_array($w, ['other', 'tablets', 'capsules', 'medicines'], true));
        foreach ($meds as $m) {
            $name = $m['name'] . ' ' . ($m['brand'] ?? '');
            $byClass = $a['drug_class'] && isset(DRUG_ALLERGY_CLASSES[$a['drug_class']]) && preg_match(DRUG_ALLERGY_CLASSES[$a['drug_class']][1], $name);
            $byName = (bool) array_filter($words, fn ($w) => preg_match('/\b' . preg_quote($w, '/') . '/i', $name));
            if ($byClass || $byName) {
                $found[(int) $m['id']][] = $a;
            }
        }
    }
    return $found;
}

/** A warning for one medicine and the allergy it matches. */
function allergy_conflict_text(array $med, array $a): string
{
    $what = $a['kind'] === 'side_effect' ? 'side effects from' : ($a['kind'] === 'intolerance' ? 'an intolerance to' : 'an allergy to');
    $sev = $a['kind'] === 'allergy' && $a['severity'] ? ' (' . mb_strtolower(ALLERGY_SEVERITY[$a['severity']] ?? '') . ')' : '';
    $group = $a['drug_class'] && preg_match(DRUG_ALLERGY_CLASSES[$a['drug_class']][1] ?? '/^$/', $med['name']) && stripos($med['name'], $a['allergen']) === false
        ? ': ' . $med['name'] . ' is one of the ' . lcfirst(DRUG_ALLERGY_CLASSES[$a['drug_class']][0]) : '';
    return "You've recorded $what {$a['allergen']}$sev$group. Check with your prescriber or pharmacist before taking {$med['name']}.";
}

/** "Penicillin (anaphylaxis), Peanuts" */
function allergy_list_text(array $allergies, bool $withSeverity = true): string
{
    return implode(', ', array_map(fn ($a) => $a['allergen'] . ($withSeverity && in_array($a['severity'], ['severe', 'anaphylaxis'], true) ? ' (' . $a['severity'] . ')' : '')
        . ($a['kind'] !== 'allergy' ? ' (' . ($a['kind'] === 'side_effect' ? 'side effects' : 'intolerance') . ')' : ''), array_filter($allergies, fn ($a) => $a['status'] === 'current')));
}

/** The line for a phone's medical ID / ICE details. */
function allergy_medical_id(array $allergies, array $profile): string
{
    $current = array_filter($allergies, fn ($a) => $a['status'] === 'current' && $a['kind'] === 'allergy');
    if (!$current) {
        return $profile['none_known'] ? 'No known allergies.' : '';
    }
    $text = 'ALLERGIES: ' . implode('; ', array_map(fn ($a) => $a['allergen'] . ($a['severity'] ? ' (' . mb_strtolower(ALLERGY_SEVERITY[$a['severity']]) . ')' : ''), $current)) . '.';
    if ($profile['aai']) {
        $text .= ' Carries adrenaline auto-injectors' . ($profile['aai_brand'] ? ' (' . $profile['aai_brand'] . ')' : '') . ($profile['aai_where'] ? ', ' . $profile['aai_where'] : '') . '.';
    }
    $others = array_filter($allergies, fn ($a) => $a['status'] === 'current' && $a['kind'] !== 'allergy' && $a['category'] === 'medicine');
    if ($others) {
        $text .= ' Not tolerated: ' . implode(', ', array_column($others, 'allergen')) . '.';
    }
    return $text;
}

/** Pollen bands, from UK pollen forecast levels (grains per cubic metre of air). */
function pollen_level(string $type, ?int $count): ?string
{
    if ($count === null) {
        return null;
    }
    [$moderate, $high, $veryHigh] = match ($type) { 'grass' => [30, 50, 150], 'tree' => [40, 80, 200], default => [10, 30, 100] };
    return $count >= $veryHigh ? 'Very high' : ($count >= $high ? 'High' : ($count >= $moderate ? 'Moderate' : 'Low'));
}

/** Which pollen someone has noted (grass / tree / weed), from their allergies. */
function pollen_types(array $allergies): array
{
    $types = [];
    foreach ($allergies as $a) {
        if ($a['status'] !== 'current' || $a['category'] !== 'environment') {
            continue;
        }
        $t = mb_strtolower($a['allergen']);
        if (str_contains($t, 'grass') || str_contains($t, 'hay fever')) {
            $types['grass'] = true;
        }
        if (preg_match('/tree|birch|alder|hazel|oak|olive|plane/', $t) || str_contains($t, 'hay fever')) {
            $types['tree'] = true;
        }
        if (preg_match('/weed|nettle|mugwort|ragweed|dock|plantain/', $t)) {
            $types['weed'] = true;
        }
        if ($t === 'pollen' || str_contains($t, 'pollen') && !preg_match('/grass|tree|weed/', $t)) {
            $types += ['grass' => true, 'tree' => true, 'weed' => true];
        }
    }
    return array_keys($types);
}

function allergies_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=allergies';
    if (str_contains(post('action'), '_weather_')) {
        weather_handle_post($user);
    }
    $profile = fn (array $cols) => q('INSERT INTO allergy_profiles (user_id, ' . implode(', ', array_keys($cols)) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", array_keys($cols))), [$uid, ...array_values($cols)]);
    switch (post('action')) {
        case 'al_save':
            $allergen = trim(mb_substr(post('allergen'), 0, 120));
            if ($allergen === '') {
                flash('error', 'Enter what you are allergic to.');
                redirect($back . '#al-list');
            }
            $category = isset(ALLERGY_CATEGORIES[post('category')]) ? post('category') : 'other';
            if (preg_match('/\blatex\b/i', $allergen)) {
                $category = 'latex';
            }
            $class = $category === 'medicine' && post('check_class') === '1' ? drug_class_of($allergen) : null;
            $cols = [$allergen, $category, $class, isset(ALLERGY_KINDS[post('kind')]) ? post('kind') : 'allergy',
                implode(',', array_intersect(array_keys(ALLERGY_REACTIONS), (array) ($_POST['reactions'] ?? []))) ?: null,
                isset(ALLERGY_SEVERITY[post('severity')]) ? post('severity') : null, isset(ALLERGY_CONFIRMED[post('confirmed')]) ? post('confirmed') : null,
                trim(mb_substr(post('since'), 0, 40)) ?: null, trim(mb_substr(post('notes'), 0, 1000)) ?: null];
            if ($id = (int) post('allergy_id')) {
                q('UPDATE allergies SET allergen = ?, category = ?, drug_class = ?, kind = ?, reactions = ?, severity = ?, confirmed = ?, since = ?, notes = ? WHERE id = ? AND user_id = ?', [...$cols, $id, $uid]);
            } else {
                q('INSERT INTO allergies (allergen, category, drug_class, kind, reactions, severity, confirmed, since, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$cols, $uid]);
                $profile(['none_known' => 0, 'none_known_on' => null]);
            }
            $conflicts = allergy_conflicts(user_allergies($uid, true), user_medications($uid));
            flash($conflicts ? 'warning' : 'success', $conflicts ? 'Saved. One of your current medicines may be affected: see below.' : 'Saved.');
            redirect($back . '#al-list');
        case 'al_status':
            $resolved = post('status') === 'resolved';
            q("UPDATE allergies SET status = ?, resolved_on = ?, resolved_note = ? WHERE id = ? AND user_id = ?",
                [$resolved ? 'resolved' : 'current', $resolved ? $today->format('Y-m-d') : null, $resolved ? (trim(mb_substr(post('resolved_note'), 0, 200)) ?: null) : null, (int) post('allergy_id'), $uid]);
            redirect($back . '#al-list');
        case 'al_delete':
            q('DELETE FROM allergies WHERE id = ? AND user_id = ?', [(int) post('allergy_id'), $uid]);
            redirect($back . '#al-list');
        case 'al_none':
            $none = post('none_known') === '1' && !user_allergies($uid, true);
            $profile(['none_known' => $none ? 1 : 0, 'none_known_on' => $none ? $today->format('Y-m-d') : null]);
            redirect($back . '#al-list');
        case 'al_aai':
            $profile(['aai' => post('aai') === '1' ? 1 : 0, 'aai_brand' => trim(mb_substr(post('aai_brand'), 0, 40)) ?: null,
                'aai_where' => trim(mb_substr(post('aai_where'), 0, 120)) ?: null]);
            if (post('aai') === '1' && post('track') === '1') {
                $have = array_column(q("SELECT kind FROM health_checks WHERE user_id = ? AND area = 'allergies'", [$uid])->fetchAll(), 'kind');
                foreach (['aai1', 'aai2', 'aai_trainer'] as $kind) {
                    if (!in_array($kind, $have, true)) {
                        add_preset_check($uid, 'allergies', $kind);
                    }
                }
                flash('success', 'Saved. Add the expiry date of each pen under Checks below.');
            } else {
                flash('success', 'Saved.');
            }
            redirect($back . '#al-aai');
    }
    redirect($back);
}

/** The allergy sections at the top of care.php?area=allergies. */
function allergies_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $allergies = user_allergies($uid);
    $current = array_filter($allergies, fn ($a) => $a['status'] === 'current');
    $profile = allergy_profile($uid);
    $meds = user_medications($uid);
    $conflicts = allergy_conflicts($allergies, $meds);
    $medsById = array_column($meds, null, 'id');
    $risk = anaphylaxis_risk($allergies, $profile);
    $cats = array_unique(array_column($current, 'category'));
    $medicalId = allergy_medical_id($allergies, $profile);
    ?>
<?php foreach ($conflicts as $medId => $list): foreach ($list as $a): ?>
<p class="flash flash-error"><?= h(allergy_conflict_text($medsById[$medId], $a)) ?> <a href="<?= h(url('medications.php#med-' . $medId)) ?>">Open in Meds</a></p>
<?php endforeach; endforeach; ?>

<section class="card preg-call<?= $risk ? ' urgent' : '' ?>" id="al-anaphylaxis">
    <?php if (!$risk): ?><details><summary><strong>Anaphylaxis: the signs and what to do</strong></summary><?php else: ?><h2>Anaphylaxis: what to do <?= help_link('allergies', 'anaphylaxis', 'anaphylaxis') ?></h2><?php endif; ?>
    <p class="small"><strong>Signs:</strong> swelling of the throat or tongue, difficulty breathing or breathing very fast, wheezing, a tight throat or hoarse voice,
        feeling faint, dizzy or confused, cold clammy skin, or collapsing. There may be a rash or hives too, but not always.</p>
    <ol class="small">
        <li><strong>Use an adrenaline auto-injector</strong> if there is one: into the outer thigh, through clothes if needed.</li>
        <li><strong>Call 999</strong>, ask for an ambulance and say it's <strong>anaphylaxis</strong> ("ana-fil-ax-is"), even if you start to feel better.</li>
        <li><strong>Lie down and raise your legs.</strong> If breathing is hard, sit up instead. If pregnant, lie on your left side. Don't stand up suddenly.</li>
        <li><strong>If there's no improvement after 5 minutes</strong>, use the second auto-injector.</li>
    </ol>
    <p class="inline-actions"><a class="btn btn-danger" href="tel:999">Call 999</a>
        <?php if ($current): ?><a class="btn" href="<?= h(url('allergy_card.php')) ?>">Allergy card</a><?php endif; ?></p>
    <?php if (!$risk): ?></details><?php endif; ?>
</section>

<section class="card" id="al-list">
    <h2>Your allergies <?= help_link('allergies', 'your-allergies', 'recording allergies') ?></h2>
    <?php if (!$allergies): ?>
        <?php if ($profile['none_known']): ?><p><strong>No known allergies</strong> <span class="muted small">(recorded <?= h(date('j M Y', strtotime((string) $profile['none_known_on']))) ?>)</span></p>
        <?php else: ?><p class="small">Add anything you're allergic to, can't tolerate, or have had a bad reaction to. If there's nothing, record that instead: prescribers need to know either way.</p><?php endif; ?>
        <?php if ($canEdit): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="al_none">
            <label class="check"><input type="checkbox" name="none_known" value="1" data-autosubmit<?= checked((bool) $profile['none_known']) ?>> I have no known allergies</label></form><?php endif; ?>
    <?php endif; ?>
    <ul class="plain">
    <?php foreach ($allergies as $a): ?>
        <li class="logline<?= $a['status'] === 'resolved' ? ' muted' : '' ?>"><span>
            <strong><?= h($a['allergen']) ?></strong>
            <?php if ($a['severity']): ?><span class="badge<?= in_array($a['severity'], ['severe', 'anaphylaxis'], true) ? ' badge-bad' : '' ?>"><?= h(ALLERGY_SEVERITY[$a['severity']]) ?></span><?php endif; ?>
            <span class="small"><?= h(ALLERGY_KINDS[$a['kind']] ?? '') ?> · <?= h(ALLERGY_CATEGORIES[$a['category']] ?? '') ?><?= $a['drug_class'] ? ' · checks all ' . h(lcfirst(DRUG_ALLERGY_CLASSES[$a['drug_class']][0] ?? '')) : '' ?></span>
            <?php if ($a['reactions'] || $a['since'] || $a['confirmed']): ?><br><span class="muted small"><?= h(implode(', ', array_map(fn ($r) => mb_strtolower(ALLERGY_REACTIONS[$r] ?? $r), $a['reactions']))) ?>
                <?= $a['since'] ? ' · since ' . h($a['since']) : '' ?><?= $a['confirmed'] ? ' · ' . h(mb_strtolower(ALLERGY_CONFIRMED[$a['confirmed']])) : '' ?></span><?php endif; ?>
            <?php if ($a['notes']): ?><br><span class="small"><?= h($a['notes']) ?></span><?php endif; ?>
            <?php if ($a['status'] === 'resolved'): ?><br><span class="small">No longer current since <?= h(date('j M Y', strtotime((string) $a['resolved_on']))) ?><?= $a['resolved_note'] ? ': ' . h($a['resolved_note']) : '' ?></span><?php endif; ?>
        </span>
        <?php if ($canEdit): ?><span class="inline-actions">
            <details class="q-edit"><summary class="btn btn-small">Edit</summary><?php allergy_form($a); ?>
                <?php if ($a['status'] === 'current'): ?>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="al_status"><input type="hidden" name="status" value="resolved"><input type="hidden" name="allergy_id" value="<?= (int) $a['id'] ?>">
                    <label>Why <input name="resolved_note" maxlength="200" placeholder="e.g. tested negative at the allergy clinic"></label><button class="btn btn-small" type="submit">No longer current</button></form>
                <?php else: ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="al_status"><input type="hidden" name="status" value="current"><input type="hidden" name="allergy_id" value="<?= (int) $a['id'] ?>"><button class="btn btn-small" type="submit">Make current again</button></form>
                <?php endif; ?>
                <form method="post" data-confirm="Delete this completely? To keep a record, mark it as no longer current instead."><?= csrf_field() ?><input type="hidden" name="action" value="al_delete"><input type="hidden" name="allergy_id" value="<?= (int) $a['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form>
            </details></span><?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $allergies || $profile['none_known'] ? '' : ' open' ?>><summary>Add an allergy</summary><?php allergy_form(null); ?></details>
    <?php endif; ?>
    <?php if ($current): ?>
    <p class="inline-actions"><a class="btn btn-small" href="<?= h(url('allergy_card.php')) ?>">Allergy card</a>
        <?php if (in_array('food', $cats, true)): ?><a class="btn btn-small" href="<?= h(url('allergy_card.php?show=food')) ?>">Card for restaurant staff</a><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($medicalId !== ''): ?>
    <details class="q-edit"><summary>For your phone's medical ID</summary>
        <textarea rows="3" readonly data-select-on-focus aria-label="Medical ID text"><?= h($medicalId) ?></textarea>
        <p class="muted small">iPhone: Health app &rarr; your picture &rarr; Medical ID. Android: Settings &rarr; Safety and emergency &rarr; Medical information. Both can be seen from the lock screen in an emergency.</p>
    </details>
    <?php endif; ?>
</section>

<?php if ($risk || $profile['aai']): ?>
<section class="card" id="al-aai">
    <h2>Adrenaline auto-injectors <?= help_link('allergies', 'adrenaline-auto-injectors', 'adrenaline pens') ?></h2>
    <ul class="small">
        <li><strong>Carry two at all times</strong> (the MHRA's advice), and make sure people around you know where they are and how to use them.</li>
        <li>Brands work differently: learn yours, and practise with a trainer pen (a free one from the manufacturer's website).</li>
        <li>Check the expiry dates, and sign up to the manufacturer's expiry alert service if there is one. Keep them at room temperature, out of direct sun.</li>
        <li>After using one, always call 999, and get it replaced.</li>
    </ul>
    <?php if ($canEdit): ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="al_aai">
        <label class="check"><input type="checkbox" name="aai" value="1"<?= checked((bool) $profile['aai']) ?>> I've been prescribed adrenaline auto-injectors</label>
        <div class="grid-2">
            <label>Which <input name="aai_brand" list="aai-brands" maxlength="40" value="<?= h((string) $profile['aai_brand']) ?>"></label>
            <label>Where you keep them <input name="aai_where" maxlength="120" placeholder="e.g. in my bag, and a spare at work" value="<?= h((string) $profile['aai_where']) ?>"></label>
        </div>
        <datalist id="aai-brands"><option>EpiPen</option><option>Jext</option><option>Emerade</option><option>Anapen</option></datalist>
        <?php if (!q("SELECT 1 FROM health_checks WHERE user_id = ? AND area = 'allergies' AND kind = 'aai1'", [$uid])->fetch()): ?>
        <label class="check"><input type="checkbox" name="track" value="1" checked> Track both pens' expiry dates, and trainer pen practice</label>
        <?php endif; ?>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (in_array('medicine', $cats, true)): ?>
<section class="card">
    <h2>Medicine allergies</h2>
    <ul class="small">
        <li>Tell every doctor, nurse, dentist and pharmacist, every time, and check it's on your GP record (you can see it in the NHS App).</li>
        <?php if (array_filter($current, fn ($a) => $a['drug_class'] === 'penicillins')): ?>
        <li><strong>Penicillin:</strong> most people with a penicillin allergy label turn out not to be allergic when tested, and it can mean getting less effective antibiotics.
            If your reaction was long ago or mild, ask your GP whether you could be tested.</li>
        <?php endif; ?>
        <li>Pillbox checks your Meds against these allergies by name<?= array_filter($current, fn ($a) => $a['drug_class']) ? ', and by group where you chose that' : '' ?>. It can't know every brand or ingredient, so always check with a pharmacist.</li>
    </ul>
</section>
<?php endif; ?>

<?php if (in_array('food', $cats, true)): ?>
<section class="card">
    <h2>Food allergies</h2>
    <ul class="small">
        <li>UK food businesses must tell you if a dish contains any of the 14 main allergens: <?= h(mb_strtolower(implode(', ', FOOD_ALLERGENS))) ?>. Ask every time, even somewhere you've eaten before.</li>
        <li>Food prepacked on the premises (such as sandwiches) must have a full ingredients list with allergens in bold (Natasha's Law).</li>
        <li>"May contain" labels are voluntary, so their absence doesn't mean it's safe.</li>
        <li>Some medicines contain food-derived ingredients such as arachis (peanut) oil, soya, lactose or gelatin: tell your pharmacist about food allergies too.</li>
    </ul>
</section>
<?php endif; ?>

<?php if (in_array('latex', $cats, true)): ?>
<section class="card">
    <h2>Latex</h2>
    <p class="small">Medical gloves, catheters, dental dams, condoms and balloons can contain latex. Tell staff before any procedure, and use latex-free condoms.
        Some people with a latex allergy also react to banana, avocado, kiwi or chestnut.</p>
</section>
<?php endif; ?>

<?php if (in_array('insect', $cats, true)): ?>
<section class="card">
    <h2>Insect stings</h2>
    <p class="small">If you've had a severe reaction to a bee or wasp sting, ask your GP about referral to an allergy clinic: venom immunotherapy can greatly reduce the risk.
        Scrape a bee sting out sideways with a fingernail or card; don't squeeze it.</p>
</section>
<?php endif; ?>

<?php if (weather_enabled() && ($types = pollen_types($allergies))) { pollen_section($user, $today, $types, $canEdit); } ?>
    <?php
}

/** Add or edit an allergy. */
function allergy_form(?array $a): void
{
    $id = $a ? (int) $a['id'] : 0;
    $f = fn (string $k) => 'al-' . $id . '-' . $k;
    ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="al_save"><input type="hidden" name="allergy_id" value="<?= $id ?>">
        <div class="grid-2">
            <label>Allergic to <input name="allergen" maxlength="120" required list="<?= $f('picks') ?>" value="<?= h((string) ($a['allergen'] ?? '')) ?>"></label>
            <label>Type <select name="category"><?php foreach (ALLERGY_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"<?= selected(($a['category'] ?? 'medicine') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>It's <select name="kind"><?php foreach (ALLERGY_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= selected(($a['kind'] ?? 'allergy') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>How bad <select name="severity"><option value="">&ndash;</option><?php foreach (ALLERGY_SEVERITY as $k => $l): ?><option value="<?= $k ?>"<?= selected(($a['severity'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>Confirmed by <select name="confirmed"><option value="">&ndash;</option><?php foreach (ALLERGY_CONFIRMED as $k => $l): ?><option value="<?= $k ?>"<?= selected(($a['confirmed'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>Since <input name="since" maxlength="40" placeholder="e.g. childhood, or 2019" value="<?= h((string) ($a['since'] ?? '')) ?>"></label>
        </div>
        <datalist id="<?= $f('picks') ?>"><?php foreach ([...ALLERGY_PICKS['medicine'], ...FOOD_ALLERGENS, ...ALLERGY_PICKS['insect'], 'Latex', ...ALLERGY_PICKS['environment'], ...ALLERGY_PICKS['contact']] as $p): ?><option><?= h($p) ?></option><?php endforeach; ?></datalist>
        <label class="check"><input type="checkbox" name="check_class" value="1"<?= checked(!$a || $a['drug_class'] || $a['category'] !== 'medicine') ?>> For a medicine: also warn about others in the same group <span class="muted small">(e.g. all penicillins)</span></label>
        <fieldset><legend>What happened</legend><div class="checks"><?php foreach (ALLERGY_REACTIONS as $k => $l): ?><label class="check"><input type="checkbox" name="reactions[]" value="<?= $k ?>"<?= checked(in_array($k, $a['reactions'] ?? [], true)) ?>> <?= h($l) ?></label><?php endforeach; ?></div></fieldset>
        <label>Notes <input name="notes" maxlength="1000" value="<?= h((string) ($a['notes'] ?? '')) ?>" placeholder="e.g. what you were given instead"></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
    <?php
}

/** Pollen for the next few days, for the kinds of pollen in someone's allergies. */
function pollen_section(array $user, DateTimeImmutable $today, array $types, bool $canEdit): void
{
    $uid = (int) $user['id'];
    $place = weather_place($uid);
    $days = $place ? weather_between($uid, $today->format('Y-m-d'), $today->modify('+3 days')->format('Y-m-d')) : [];
    $hasPollen = (bool) array_filter($days, fn ($d) => $d['pollen_grass'] !== null || $d['pollen_tree'] !== null || $d['pollen_weed'] !== null);
    $names = ['grass' => 'Grass', 'tree' => 'Tree', 'weed' => 'Weed'];
    ?>
<section class="card" id="al-pollen">
    <h2>Pollen <?= help_link('allergies', 'pollen', 'pollen counts') ?></h2>
    <?php if (!$place): ?>
    <p class="small">Add your town to see pollen counts for the next few days. Only the town's location (to about 10 km) is sent to Open-Meteo, a free weather service.</p>
    <?php elseif (!$hasPollen): ?>
    <p class="small">No pollen counts for <?= h($place['name']) ?> at the moment. They're only available in Europe, and only for pollen in season.</p>
    <?php else: ?>
    <p class="small">For <strong><?= h($place['name']) ?></strong> (highest count in the day):</p>
    <div class="table-wrap"><table><thead><tr><th></th><?php foreach ($types as $t): ?><th><?= $names[$t] ?> pollen</th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($days as $day => $d): ?><tr><td><?= h($day === $today->format('Y-m-d') ? 'Today' : date('D j M', strtotime($day))) ?></td>
            <?php foreach ($types as $t): $n = $d['pollen_' . $t] === null ? null : (int) $d['pollen_' . $t]; $lvl = pollen_level($t, $n); ?>
            <td><?= $lvl ? '<strong' . (in_array($lvl, ['High', 'Very high'], true) ? ' class="warn"' : '') . '>' . h($lvl) . '</strong> <span class="muted small">' . $n . '</span>' : '<span class="muted">&ndash;</span>' ?></td>
            <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="muted small">Counts are grains per cubic metre of air, from a forecast model, so they're a guide. Grass pollen is usually highest from late May to July, tree pollen from late March to mid-May, and weed pollen from late June to September.</p>
    <?php endif; ?>
    <details class="q-edit"><summary>Hay fever tips</summary>
        <ul class="small">
            <li>Start a steroid nasal spray (and antihistamines if you use them) a couple of weeks before your pollen season, and use them every day through it.</li>
            <li>Put petroleum jelly around your nostrils, and wear wraparound sunglasses.</li>
            <li>Shower and change clothes after being outside; keep windows and doors shut when counts are high; don't dry washing outside.</li>
            <li>A pharmacist can help you choose treatment. See a GP if it isn't working or you also get wheezing.</li>
        </ul>
    </details>
    <?php if ($canEdit) { weather_place_form($place, 'al'); } ?>
</section>
    <?php
}
