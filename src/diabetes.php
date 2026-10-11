<?php
declare(strict_types=1);

/*
 * Diabetes (the "Diabetes" Care category, care.php?area=diabetes): type 1, type 2 and others.
 * - Glucose from Readings (finger-prick, or CGM via Nightscout): time in range, patterns by
 *   time of day, a chart, and the cycle overlay; HbA1c from Blood tests; ketone guidance.
 * - Insulin and carbs log; pens and supplies as Care checks; hypo-risk and SGLT2 flags on medicines.
 * - Safety: a one-tap hypo guide with a 15-minute timer (hypo.php), sick-day mode, and the DVLA
 *   "five to drive" check before driving.
 * - The annual care checks as a done / due list, diabetic eye screening separately.
 * - Links: pregnancy planning, gestational diabetes testing, medical ID, a clinician summary.
 * Following NHS, NICE, Diabetes UK and DVLA guidance; it never adjusts doses.
 */

const DIABETES_TYPES = ['1' => 'Type 1', '2' => 'Type 2', 'lada' => 'LADA', 'mody' => 'MODY', 'gestational' => 'Gestational', 'prediabetes' => 'Prediabetes (at risk)', 'other' => 'Other'];
const INSULIN_KINDS = ['basal' => 'Basal (long-acting)', 'bolus' => 'Bolus (mealtime)', 'correction' => 'Correction'];
// Medicines that can cause hypos, and SGLT2 inhibitors (often paused when unwell, if the team has said so).
const HYPO_RISK_PATTERN = '/\b(insulin\w*|novorapid|humalog|fiasp|lyumjev|apidra|lantus|levemir|tresiba|toujeo|abasaglar|semglee|humulin|insulatard|gliclazide|glimepiride|glibenclamide|glipizide|tolbutamide|repaglinide|nateglinide)\b/i';
const SGLT2_PATTERN = '/\b(\w*gliflozin|forxiga|jardiance|invokana|steglatro)\b/i';
const DIABETES_MED_PATTERN = '/\b(insulin\w*|metformin|gliclazide|glimepiride|\w*gliflozin|\w*gliptin|\w*glutide|tirzepatide|mounjaro|ozempic|trulicity|victoza|pioglitazone|lantus|levemir|tresiba|novorapid|humalog|fiasp)\b/i';

// The annual care checks (NICE), plus diabetic eye screening (a separate invitation).
const DIABETES_ANNUAL = ['dm_hba1c' => 'HbA1c', 'dm_bp' => 'Blood pressure', 'dm_cholesterol' => 'Cholesterol', 'dm_kidney' => 'Kidney function (blood test)',
    'dm_acr' => 'Urine albumin (ACR)', 'dm_feet' => 'Foot check', 'dm_weight' => 'Weight and BMI', 'dm_smoking' => 'Smoking status', 'dm_eyes' => 'Diabetic eye screening'];

/** [level, advice] for a blood ketone reading in mmol/L. */
function ketone_advice(float $v): array
{
    return match (true) {
        $v < 0.6 => ['ok', 'Under 0.6: normal.'],
        $v < 1.5 => ['warn', '0.6 to 1.4: raised. Follow your sick-day rules, drink sugar-free fluids, and check again in 2 hours.'],
        $v < 3.0 => ['bad', '1.5 to 2.9: high risk of ketoacidosis. Contact your diabetes team now (or NHS 111 out of hours).'],
        default => ['bad', '3.0 or more: this is an emergency. Go to A&E or call 999 now.'],
    };
}

function diabetes_profile(int $userId): array
{
    return q('SELECT * FROM diabetes_profiles WHERE user_id = ?', [$userId])->fetch()
        ?: ['user_id' => $userId, 'type' => null, 'target_low' => 3.9, 'target_high' => 10.0, 'hba1c_target' => null, 'sick_since' => null, 'drives' => 0];
}

/** Does this person look like they have diabetes (profile, the Care category, or diabetes medicines)? */
function has_diabetes(array $user): bool
{
    $p = diabetes_profile((int) $user['id']);
    return ($p['type'] && $p['type'] !== 'prediabetes') || (bool) array_filter(user_medications((int) $user['id']), fn ($m) => preg_match(DIABETES_MED_PATTERN, $m['name']));
}

/** Glucose readings (mmol/L) since a time: [[unix time, value], ...]. */
function glucose_points(int $userId, int $sinceTs): array
{
    return array_map(fn ($r) => [strtotime($r['recorded_at'] . ' UTC'), (float) $r['value1']],
        q("SELECT recorded_at, value1 FROM health_readings WHERE user_id = ? AND metric = 'blood_glucose' AND recorded_at >= ? ORDER BY recorded_at",
            [$userId, gmdate('Y-m-d H:i:s', $sinceTs)])->fetchAll());
}

/**
 * Time in range and friends (international consensus ranges): very low < 3.0, low 3.0-3.8,
 * in range (target, usually 3.9-10.0), high to 13.9, very high above; average; and by part of day.
 */
function glucose_stats(array $points, float $low, float $high, DateTimeZone $tz): ?array
{
    if (!$points) {
        return null;
    }
    $n = count($points);
    $vals = array_column($points, 1);
    $pct = fn (callable $f) => 100 * count(array_filter($vals, $f)) / $n;
    $parts = ['Night (00 to 06)' => [], 'Morning (06 to 12)' => [], 'Afternoon (12 to 18)' => [], 'Evening (18 to 24)' => []];
    $keys = array_keys($parts);
    foreach ($points as [$ts, $v]) {
        $parts[$keys[intdiv((int) (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('G'), 6)]][] = $v;
    }
    return [
        'n' => $n, 'mean' => array_sum($vals) / $n,
        'very_low' => $pct(fn ($v) => $v < 3.0), 'low' => $pct(fn ($v) => $v >= 3.0 && $v < $low),
        'in_range' => $pct(fn ($v) => $v >= $low && $v <= $high), 'high' => $pct(fn ($v) => $v > $high && $v <= 13.9), 'very_high' => $pct(fn ($v) => $v > 13.9),
        'parts' => array_map(fn ($vs) => $vs ? ['n' => count($vs), 'mean' => array_sum($vs) / count($vs),
            'in_range' => 100 * count(array_filter($vs, fn ($v) => $v >= $low && $v <= $high)) / count($vs),
            'low' => 100 * count(array_filter($vs, fn ($v) => $v < $low)) / count($vs)] : null, $parts),
    ];
}

/** Low episodes: readings under 4 (more than an hour apart) plus hypos logged with the guide. */
function hypo_episodes(int $userId, int $sinceTs): int
{
    $count = 0;
    $last = 0;
    foreach (glucose_points($userId, $sinceTs) as [$ts, $v]) {
        if ($v < 4.0 && $ts - $last > 3600) {
            $count++;
            $last = $ts;
        }
    }
    $logged = (int) q("SELECT COUNT(*) FROM hypo_events WHERE user_id = ? AND at >= ? AND glucose IS NULL", [$userId, gmdate('Y-m-d H:i:s', $sinceTs)])->fetchColumn();
    return $count + $logged;
}

/* ---------- Nightscout (self-hosted CGM data: Libre, Dexcom and others) ---------- */

/** Is this a public http(s) address (not this server's own network), unless an admin allows private ones? */
function nightscout_url_ok(string $url): bool
{
    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])) {
        return false;
    }
    if (config('nightscout.allow_private', false)) {
        return true;
    }
    $ips = gethostbynamel($parts['host']) ?: [];
    return $ips && !array_filter($ips, fn ($ip) => !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
}

function nightscout_get(string $base, string $token, string $path, array $query): array
{
    $url = rtrim($base, '/') . $path . '?' . http_build_query($query + ($token !== '' ? ['token' => $token] : []));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    // Connect to the address that was checked (so a name can't switch to an internal address in between).
    $parts = parse_url($base);
    $ip = gethostbyname((string) $parts['host']);
    if (!config('nightscout.allow_private', false)) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('That Nightscout address is not allowed.');
        }
        curl_setopt($ch, CURLOPT_RESOLVE, [$parts['host'] . ':' . ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) . ':' . $ip]);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false || $code !== 200) {
        throw new RuntimeException($code === 401 ? 'Nightscout refused access: check the access token (it needs the "readable" role).' : "Couldn't reach Nightscout (" . ($code ?: curl_error($ch)) . ').');
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Nightscout didn't send readings. Check the address.");
    }
    return $data;
}

/** Import CGM readings (and insulin and carbs) since a time. @return array{added: int, updated: int} */
function nightscout_sync(array $conn, int $sinceTs): array
{
    $uid = (int) $conn['user_id'];
    $base = (string) $conn['external_user'];
    if (!nightscout_url_ok($base)) {
        throw new RuntimeException('That Nightscout address is not allowed.');
    }
    $token = (string) unseal($conn['access_token']);
    $rows = [];
    foreach (nightscout_get($base, $token, '/api/v1/entries/sgv.json', ['count' => 5000, 'find[date][$gte]' => $sinceTs * 1000]) as $e) {
        if (isset($e['sgv'], $e['date']) && is_numeric($e['sgv']) && (float) $e['sgv'] > 0) {
            $rows[] = connector_row('nightscout', 'blood_glucose', [round((float) $e['sgv'] / 18.0, 1)], intdiv((int) $e['date'], 1000), (string) ($e['_id'] ?? $e['date']), 'CGM');
        }
    }
    $result = save_health_rows($uid, $rows, 'nightscout');
    // Insulin and carbs from Nightscout treatments (bolus, basal, meals).
    foreach (nightscout_get($base, $token, '/api/v1/treatments.json', ['count' => 1000, 'find[created_at][$gte]' => gmdate('Y-m-d\TH:i:s\Z', $sinceTs)]) as $t) {
        $units = isset($t['insulin']) && is_numeric($t['insulin']) ? (float) $t['insulin'] : null;
        $carbs = isset($t['carbs']) && is_numeric($t['carbs']) ? (float) $t['carbs'] : null;
        if (($units || $carbs) && !empty($t['created_at'])) {
            $kind = stripos((string) ($t['eventType'] ?? ''), 'basal') !== false || stripos((string) ($t['eventType'] ?? ''), 'temp') !== false ? 'basal'
                : (stripos((string) ($t['eventType'] ?? ''), 'correction') !== false ? 'correction' : 'bolus');
            q('INSERT IGNORE INTO insulin_logs (user_id, taken_at, kind, units, carbs, notes, external_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uid, gmdate('Y-m-d H:i:s', strtotime((string) $t['created_at'])), $kind, $units, $carbs, 'From Nightscout', sha1('ns|' . ($t['_id'] ?? $t['created_at']))]);
        }
    }
    q('UPDATE connections SET last_sync_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?', [$conn['id']]);
    return $result;
}

/** Actions posted from care.php?area=diabetes ('dm_...'); always redirects. */
function diabetes_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=diabetes';
    $p = diabetes_profile($uid);
    switch (post('action')) {
        case 'dm_profile':
            $low = is_numeric(post('target_low')) ? max(3.0, min(8.0, (float) post('target_low'))) : 3.9;
            $high = is_numeric(post('target_high')) ? max($low + 1, min(20.0, (float) post('target_high'))) : 10.0;
            q('INSERT INTO diabetes_profiles (user_id, type, target_low, target_high, hba1c_target, drives) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE type = VALUES(type), target_low = VALUES(target_low), target_high = VALUES(target_high), hba1c_target = VALUES(hba1c_target), drives = VALUES(drives)',
                [$uid, isset(DIABETES_TYPES[post('type')]) ? post('type') : null, $low, $high, is_numeric(post('hba1c_target')) ? (int) post('hba1c_target') : null, post('drives') === '1' ? 1 : 0]);
            flash('success', 'Saved.');
            redirect($back . '#dm-about');
        case 'dm_sick':
            q('INSERT INTO diabetes_profiles (user_id, sick_since) VALUES (?, ?) ON DUPLICATE KEY UPDATE sick_since = VALUES(sick_since)', [$uid, post('on') === '1' ? utc_now() : null]);
            redirect($back . '#dm-sick');
        case 'dm_insulin':
            $at = local_to_utc((parse_date(post('date')) ?: $today)->format('Y-m-d') . ' ' . (preg_match('/^\d\d:\d\d$/', post('time')) ? post('time') : local_time(utc_now(), 'H:i')));
            $units = is_numeric(post('units')) ? max(0, min(300, (float) post('units'))) : null;
            $carbs = is_numeric(post('carbs')) ? max(0, min(1000, (float) post('carbs'))) : null;
            if (($units || $carbs) && $at && $at <= gmdate('Y-m-d H:i:s', time() + 300)) {
                q('INSERT INTO insulin_logs (user_id, taken_at, kind, units, carbs, glucose, medication_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                    $uid, $at, isset(INSULIN_KINDS[post('kind')]) ? post('kind') : 'bolus', $units, $carbs,
                    is_numeric(post('glucose')) ? max(0.5, min(40, (float) post('glucose'))) : null,
                    post('medication_id') !== '' && find_medication((int) post('medication_id'), $uid) ? (int) post('medication_id') : null, trim(mb_substr(post('notes'), 0, 300)) ?: null]);
                if (is_numeric(post('glucose'))) {
                    q("INSERT INTO health_readings (user_id, metric, value1, context, recorded_at) VALUES (?, 'blood_glucose', ?, 'Before meal', ?)", [$uid, (float) post('glucose'), $at]);
                }
                flash('success', 'Saved.');
            }
            redirect($back . '#dm-insulin');
        case 'dm_insulin_delete':
            q('DELETE FROM insulin_logs WHERE id = ? AND user_id = ?', [(int) post('log_id'), $uid]);
            redirect($back . '#dm-insulin');
        case 'dm_drive':
            $v = is_numeric(post('glucose')) ? (float) post('glucose') : null;
            if ($v !== null && $v >= 0.5 && $v <= 40) {
                q("INSERT INTO health_readings (user_id, metric, value1, context, recorded_at) VALUES (?, 'blood_glucose', ?, 'Before driving', UTC_TIMESTAMP())", [$uid, $v]);
                $_SESSION['dm_drive'] = $v;
            }
            redirect($back . '#dm-drive');
        case 'dm_annual':
            $have = q("SELECT kind FROM health_checks WHERE user_id = ? AND area = 'diabetes'", [$uid])->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_keys(DIABETES_ANNUAL) as $kind) {
                if (!in_array($kind, $have, true)) {
                    add_preset_check($uid, 'diabetes', $kind);
                }
            }
            flash('success', 'Annual care checks added. Mark each one done when it happens.');
            redirect($back . '#dm-annual');
        case 'dm_nightscout':
            $url = rtrim(trim(post('url')), '/');
            if (post('disconnect') === '1') {
                q("DELETE FROM connections WHERE user_id = ? AND provider = 'nightscout'", [$uid]);
                flash('success', 'Nightscout disconnected. Readings already imported are kept.');
            } elseif (!nightscout_url_ok($url)) {
                flash('error', 'Enter your Nightscout address, e.g. https://yourname.herokuapp.com (it must be reachable from the internet).');
            } else {
                q("INSERT INTO connections (user_id, provider, access_token, external_user) VALUES (?, 'nightscout', ?, ?)
                   ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), external_user = VALUES(external_user), last_error = NULL",
                    [$uid, seal(trim(post('token'))), $url]);
                $conn = q("SELECT * FROM connections WHERE user_id = ? AND provider = 'nightscout'", [$uid])->fetch();
                try {
                    $r = nightscout_sync($conn, time() - 14 * 86400);
                    flash('success', "Connected. Imported {$r['added']} readings from the last 2 weeks; it keeps up to date about once an hour.");
                } catch (Throwable $e) {
                    q('UPDATE connections SET last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $conn['id']]);
                    flash('error', $e->getMessage());
                }
            }
            redirect($back . '#dm-glucose');
    }
    redirect($back);
}

/** The diabetes sections at the top of care.php?area=diabetes. */
function diabetes_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $p = diabetes_profile($uid);
    $tz = user_tz($user);
    $low = (float) $p['target_low'];
    $high = (float) $p['target_high'];
    $points14 = glucose_points($uid, time() - 14 * 86400);
    $stats = glucose_stats($points14, $low, $high, $tz);
    $last = $points14 ? end($points14) : null;
    $ketone = q("SELECT * FROM health_readings WHERE user_id = ? AND metric = 'blood_ketones' ORDER BY recorded_at DESC LIMIT 1", [$uid])->fetch();
    $hba1c = q("SELECT * FROM lab_results WHERE user_id = ? AND test = 'hba1c' ORDER BY taken_at DESC LIMIT 1", [$uid])->fetch();
    $hypos = hypo_episodes($uid, time() - 30 * 86400);
    $meds = user_medications($uid);
    $hypoMeds = array_filter($meds, fn ($m) => preg_match(HYPO_RISK_PATTERN, $m['name']));
    $sglt2 = array_filter($meds, fn ($m) => preg_match(SGLT2_PATTERN, $m['name']));
    $insulinMeds = array_filter($meds, fn ($m) => preg_match('/insulin|novorapid|humalog|fiasp|lyumjev|apidra|lantus|levemir|tresiba|toujeo|abasaglar|semglee|humulin|insulatard/i', $m['name']));
    $insulin = q('SELECT * FROM insulin_logs WHERE user_id = ? AND taken_at >= ? ORDER BY taken_at DESC', [$uid, gmdate('Y-m-d H:i:s', time() - 7 * 86400)])->fetchAll();
    $ns = q("SELECT * FROM connections WHERE user_id = ? AND provider = 'nightscout'", [$uid])->fetch();
    $annual = [];
    foreach (q("SELECT * FROM health_checks WHERE user_id = ? AND area = 'diabetes' AND kind LIKE 'dm\\_%'", [$uid]) as $c) {
        $annual[$c['kind']] = $c;
    }
    $drive = $_SESSION['dm_drive'] ?? null;
    unset($_SESSION['dm_drive']);
    $todayYmd = $today->format('Y-m-d');
    ?>
<?php if ($p['sick_since']): ?>
<section class="card preg-call" id="dm-sick">
    <h2>Sick-day rules</h2>
    <p class="small">Unwell since <?= h(local_time($p['sick_since'], 'D j M, H:i')) ?>. Follow the plan from your diabetes team; in general:</p>
    <ul class="small">
        <li>Check your glucose at least every 2 to 4 hours, including overnight<?= $insulinMeds ? ', and blood ketones too if glucose is over 13 or you feel sick' : '' ?>.</li>
        <li>Keep drinking: sip at least 100 ml of sugar-free fluid every hour. If you can't eat, have sugary drinks or snacks to keep some carbohydrate going in.</li>
        <?php if ($insulinMeds): ?><li><strong>Never stop your insulin</strong>, even if you're not eating: you may need more, not less. Ask your team about extra correction doses.</li><?php endif; ?>
        <?php foreach ($sglt2 as $m): ?><li>Your team may have told you to stop <?= h($m['name']) ?> while you're unwell (it raises the risk of ketoacidosis): restart when you're eating and drinking normally.</li><?php endforeach; ?>
        <li>Metformin and some blood pressure or water tablets may also need pausing if you can't keep fluids down: only if your team has said so.</li>
        <li>Get help (diabetes team, NHS 111, or 999 in an emergency) if you keep vomiting, can't keep fluids down, ketones are 1.5 or more, glucose stays high, or you feel drowsy or confused.</li>
    </ul>
    <?php if ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dm_sick"><input type="hidden" name="on" value="0"><button class="btn btn-small" type="submit">I'm better now</button></form><?php endif; ?>
</section>
<?php endif; ?>

<section class="card" id="dm-glucose">
    <div class="card-head">
        <h2>Glucose <?= help_link('diabetes', 'glucose', 'glucose and time in range') ?></h2>
        <span class="inline-actions"><a class="btn btn-small btn-danger" href="<?= h(url('hypo.php')) ?>">Hypo guide</a><a class="btn btn-small" href="<?= h(url('diabetes_summary.php')) ?>">Clinician summary</a></span>
    </div>
    <div class="tiles">
        <?php if ($last): ?><div class="tile"><span class="tile-label">Latest</span><span class="tile-value<?= $last[1] < 4 ? ' warn' : '' ?>"><?= h(number_format($last[1], 1)) ?></span>
            <span class="tile-meta">mmol/L · <?= h((new DateTimeImmutable('@' . $last[0]))->setTimezone($tz)->format('D H:i')) ?></span></div><?php endif; ?>
        <?php if ($stats): ?>
        <div class="tile"><span class="tile-label">Time in range (14 days)</span><span class="tile-value"><?= (int) round($stats['in_range']) ?>%</span>
            <span class="tile-meta"><?= h(num($low)) ?> to <?= h(num($high)) ?> mmol/L · average <?= h(number_format($stats['mean'], 1)) ?></span></div>
        <?php endif; ?>
        <div class="tile"><span class="tile-label">Lows (30 days)</span><span class="tile-value"><?= $hypos ?></span><span class="tile-meta">under 4 mmol/L</span></div>
        <?php if ($hba1c): ?><div class="tile"><span class="tile-label">HbA1c</span><span class="tile-value"><?= h(num($hba1c['value'])) ?></span>
            <span class="tile-meta">mmol/mol · <?= h(local_time($hba1c['taken_at'], 'M Y')) ?><?= $p['hba1c_target'] ? ' · target ' . (int) $p['hba1c_target'] : '' ?></span></div><?php endif; ?>
        <?php if ($ketone): [$kLevel, $kText] = ketone_advice((float) $ketone['value1']); ?>
        <div class="tile"><span class="tile-label">Ketones</span><span class="tile-value<?= $kLevel !== 'ok' ? ' warn' : '' ?>"><?= h(num($ketone['value1'])) ?></span>
            <span class="tile-meta">mmol/L · <?= h(local_time($ketone['recorded_at'], 'D j M H:i')) ?></span></div>
        <?php endif; ?>
    </div>
    <?php if ($ketone && $ketone['recorded_at'] > gmdate('Y-m-d H:i:s', time() - 12 * 3600) && $kLevel !== 'ok'): ?><p class="flash flash-<?= $kLevel === 'bad' ? 'error' : 'warning' ?>"><?= h($kText) ?></p><?php endif; ?>
    <?php if ($stats): ?>
    <div class="tir-bar" role="img" aria-label="Time in ranges">
        <?php foreach (['very_low' => 'Very low', 'low' => 'Low', 'in_range' => 'In range', 'high' => 'High', 'very_high' => 'Very high'] as $k => $l): if ($stats[$k] >= 0.5): ?>
        <span class="tir-<?= $k ?> w<?= (int) min(100, max(1, round($stats[$k]))) ?>" title="<?= h($l) ?>: <?= (int) round($stats[$k]) ?>%"></span><?php endif; endforeach; ?>
    </div>
    <p class="muted small">Very low under 3.0 <?= (int) round($stats['very_low']) ?>% · low <?= (int) round($stats['low']) ?>% · in range <?= (int) round($stats['in_range']) ?>% · high <?= (int) round($stats['high']) ?>% ·
        very high over 13.9 <?= (int) round($stats['very_high']) ?>% (<?= $stats['n'] ?> readings). A common aim with CGM is over 70% in range and under 4% low, but your team will set yours.</p>
    <?= svg_line_chart([['label' => 'Glucose', 'unit' => 'mmol/L', 'points' => $points14]], time() - 14 * 86400, time(), 'Glucose over 14 days',
        [['low' => $low, 'high' => $high, 'class' => 'band-target', 'label' => 'Target range']]) ?>
    <h3>By time of day</h3>
    <div class="table-wrap"><table><thead><tr><th></th><th class="num">Average</th><th class="num">In range</th><th class="num">Low</th></tr></thead><tbody>
        <?php foreach ($stats['parts'] as $part => $s): if ($s): ?><tr><td><?= h($part) ?></td><td class="num"><?= h(number_format($s['mean'], 1)) ?></td><td class="num"><?= (int) round($s['in_range']) ?>%</td><td class="num<?= $s['low'] >= 4 ? ' warn' : '' ?>"><?= (int) round($s['low']) ?>%</td></tr><?php endif; endforeach; ?>
    </tbody></table></div>
    <?php if (cycle_anchors($uid)) {
        $pts = glucose_points($uid, time() - 120 * 86400);
        $lut = $oth = [];
        foreach ($pts as [$ts, $v]) {
            is_luteal_day($uid, (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d')) ? $lut[] = $v : $oth[] = $v;
        }
        if (count($lut) >= 10 && count($oth) >= 10) {
            echo '<p class="small">In the 2 weeks before a period your glucose averaged ' . h(number_format(array_sum($lut) / count($lut), 1)) . ' mmol/L, and '
                . h(number_format(array_sum($oth) / count($oth), 1)) . ' at other times. Hormones can change insulin needs: worth mentioning to your team.</p>';
        }
    } ?>
    <?php else: ?>
    <p class="small">Log glucose in <a href="<?= h(url('readings.php?metric=blood_glucose')) ?>">Readings</a>, or connect a CGM through Nightscout below, to see time in range and patterns.</p>
    <?php endif; ?>
    <details class="q-edit"><summary>CGM: connect Nightscout<?= $ns ? ' (connected)' : '' ?></summary>
        <p class="small">Nightscout is a free, self-hosted service that collects readings from Libre, Dexcom and other CGMs. Pillbox imports its glucose readings (and insulin and carbs) about once an hour.</p>
        <?php if ($ns): ?><p class="small">Connected to <?= h($ns['external_user']) ?><?= $ns['last_sync_at'] ? ', last checked ' . h(local_time($ns['last_sync_at'], 'j M H:i')) : '' ?>.<?= $ns['last_error'] ? ' <span class="warn">' . h($ns['last_error']) . '</span>' : '' ?></p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="dm_nightscout">
            <div class="grid-2">
                <label>Nightscout address <input type="url" name="url" required value="<?= h((string) ($ns['external_user'] ?? '')) ?>" placeholder="https://your-site.example"></label>
                <label><span>Access token <span class="muted small">(Admin tools → Subjects, with the "readable" role)</span></span><input name="token" autocomplete="off"></label>
            </div>
            <span class="inline-actions"><button class="btn btn-small btn-primary" type="submit"><?= $ns ? 'Save and sync now' : 'Connect' ?></button>
                <?php if ($ns): ?><button class="btn btn-small" type="submit" name="disconnect" value="1" formnovalidate>Disconnect</button><?php endif; ?></span>
        </form>
        <?php endif; ?>
    </details>
</section>

<?php if ($insulinMeds || $insulin): ?>
<section class="card" id="dm-insulin">
    <h2>Insulin and carbs</h2>
    <?php $days = [];
    foreach ($insulin as $l) {
        $d = local_time($l['taken_at'], 'Y-m-d');
        $days[$d]['basal'] = ($days[$d]['basal'] ?? 0) + ($l['kind'] === 'basal' ? (float) $l['units'] : 0);
        $days[$d]['bolus'] = ($days[$d]['bolus'] ?? 0) + ($l['kind'] !== 'basal' ? (float) $l['units'] : 0);
        $days[$d]['carbs'] = ($days[$d]['carbs'] ?? 0) + (float) $l['carbs'];
    }
    if ($days): ?>
    <div class="table-wrap"><table><thead><tr><th>Day</th><th class="num">Basal (units)</th><th class="num">Bolus and corrections</th><th class="num">Carbs (g)</th></tr></thead><tbody>
        <?php foreach ($days as $d => $t): ?><tr><td><?= h(date('D j M', strtotime($d))) ?></td><td class="num"><?= h(num($t['basal'])) ?></td><td class="num"><?= h(num($t['bolus'])) ?></td><td class="num"><?= h(num($t['carbs'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= $insulin ? '' : ' open' ?>><summary>Log a dose or meal</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="dm_insulin">
            <div class="grid-2">
                <label>Type <select name="kind"><?php foreach (INSULIN_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
                <?php if ($insulinMeds): ?><label>Insulin <select name="medication_id"><option value="">&ndash;</option><?php foreach ($insulinMeds as $m): ?><option value="<?= (int) $m['id'] ?>"><?= h($m['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Units <input type="number" name="units" min="0" max="300" step="0.5" class="count-input"></label>
                <label>Carbs (g) <input type="number" name="carbs" min="0" max="1000" step="1" class="count-input"></label>
                <label>Glucose before (mmol/L) <input type="number" name="glucose" min="0.5" max="40" step="0.1" class="count-input"></label>
                <label>When <span class="range-pair"><input type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"><input type="time" name="time" value="<?= h(local_time(utc_now(), 'H:i')) ?>"></span></label>
            </div>
            <label>Notes <input name="notes" maxlength="300"></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
        </form>
    </details>
    <?php endif; ?>
    <p class="muted small">Spare pens and cartridges: in the fridge (2 to 8°C, never frozen). The pen in use: at room temperature, usually for up to 4 weeks (check your insulin's leaflet). Track stock and opening dates with the checks below.</p>
</section>
<?php endif; ?>

<?php if ($hypoMeds): ?>
<p class="flash flash-info small"><?= h(implode(', ', array_map(fn ($m) => $m['name'], $hypoMeds))) ?> can cause low blood sugar (hypos). Keep fast-acting sugar with you, and know your <a href="<?= h(url('hypo.php')) ?>">hypo plan</a>.</p>
<?php endif; ?>

<section class="card" id="dm-drive">
    <h2>Before driving <?= help_link('diabetes', 'before-driving', 'driving') ?></h2>
    <?php if ($drive !== null): ?>
    <p class="flash <?= $drive > 5 ? 'flash-success' : 'flash-error' ?>"><?= $drive > 5 ? h(number_format($drive, 1)) . ' mmol/L: above 5, so OK to drive. Check again every 2 hours on a long journey.'
        : h(number_format($drive, 1)) . " mmol/L: don't drive. Have a snack (and treat a hypo if under 4), then check again: you need to be above 5." ?></p>
    <?php endif; ?>
    <?php if (!$p['drives'] && !$hypoMeds): ?>
    <p class="small">If you drive and take insulin (or tablets that can cause hypos), the DVLA's "five to drive" rule applies: tick "I drive" under About to keep this check here.</p>
    <?php else: ?>
    <ul class="small checklist">
        <li>Check your glucose no more than 2 hours before driving, and every 2 hours on long journeys. It must be above 5 mmol/L ("five to drive").</li>
        <li>Keep your meter and fast-acting sugar within reach in the car.</li>
        <li>If you feel a hypo coming on: stop safely, take the key out, move to the passenger seat, treat it, and wait 45 minutes after your glucose is back to normal before driving.</li>
    </ul>
    <?php if ($canEdit): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="dm_drive">
        <label>Glucose now (mmol/L) <input type="number" name="glucose" min="0.5" max="40" step="0.1" required class="count-input"></label>
        <button class="btn btn-primary btn-small" type="submit">Check</button></form>
    <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" id="dm-annual">
    <h2>Annual care checks</h2>
    <p class="muted small">Everyone with diabetes should have these every year. Diabetic eye screening is a separate invitation.</p>
    <ul class="annual-list">
        <?php foreach (DIABETES_ANNUAL as $kind => $label): $c = $annual[$kind] ?? null;
            $done = $c && $c['last_done'] && $c['last_done'] >= $today->modify('-12 months')->format('Y-m-d'); ?>
        <li class="<?= $done ? 'done' : 'due' ?>"><span aria-hidden="true"><?= $done ? '&#10003;' : '&#9675;' ?></span> <?= h($label) ?>
            <span class="muted small"><?= $c && $c['last_done'] ? (($done ? 'done ' : 'last ') . h(date('M Y', strtotime($c['last_done'])))) : ($c ? 'not done yet' : 'not tracked') ?><?= $done ? '' : ' · due' ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($canEdit && count($annual) < count(DIABETES_ANNUAL)): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dm_annual"><button class="btn btn-small" type="submit">Track all the annual checks</button></form>
    <?php endif; ?>
</section>

<section class="card" id="dm-about">
    <h2>About your diabetes</h2>
    <?php if ($canEdit): ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="dm_profile">
        <div class="grid-2">
            <label>Type <select name="type"><option value="">&ndash;</option><?php foreach (DIABETES_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= selected((string) $p['type'] === (string) $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label><span>HbA1c target <span class="muted small">(mmol/mol, often 48 to 53)</span></span><input type="number" name="hba1c_target" min="30" max="100" class="count-input" value="<?= h((string) $p['hba1c_target']) ?>"></label>
            <label>Glucose target range (mmol/L) <span class="range-pair"><input type="number" name="target_low" step="0.1" min="3" max="8" class="count-input" value="<?= h(num($p['target_low'])) ?>"> to
                <input type="number" name="target_high" step="0.1" min="5" max="20" class="count-input" value="<?= h(num($p['target_high'])) ?>"></span></label>
        </div>
        <label class="check"><input type="checkbox" name="drives" value="1"<?= checked((bool) $p['drives']) ?>> I drive</label>
        <span class="inline-actions"><button class="btn btn-small" type="submit">Save</button>
            <?php if (!$p['sick_since']): ?><button class="btn btn-small" type="submit" form="dm-sick-form">I'm unwell: sick-day rules</button><?php endif; ?></span>
    </form>
    <form method="post" id="dm-sick-form"><?= csrf_field() ?><input type="hidden" name="action" value="dm_sick"><input type="hidden" name="on" value="1"></form>
    <?php endif; ?>
    <ul class="small">
        <li><strong>Pregnancy:</strong> plan ahead with your diabetes team. You'll usually need a higher dose of folic acid (5 mg, on prescription) and tighter glucose targets.</li>
        <li>Blood pressure counts too: log it in <a href="<?= h(url('readings.php?metric=blood_pressure')) ?>">Readings</a>.</li>
    </ul>
    <?php if ($p['type'] || $hypoMeds): ?>
    <details class="q-edit"><summary>For your phone's medical ID</summary>
        <textarea rows="3" readonly data-select-on-focus aria-label="Medical ID text"><?= h(($p['type'] ? DIABETES_TYPES[$p['type']] . ' diabetes' : 'Diabetes') . ($insulinMeds ? ', uses insulin' : '')
            . ($hypoMeds ? '. If I am confused, drowsy or acting strangely I may be having a hypo (low blood sugar): if I can swallow, give me sugar (glucose tablets, a non-diet fizzy drink or juice). If I cannot swallow or am unconscious, put me in the recovery position and call 999.' : '.')) ?></textarea>
    </details>
    <?php endif; ?>
</section>
    <?php
}
