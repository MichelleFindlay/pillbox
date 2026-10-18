<?php
declare(strict_types=1);

/*
 * Connected services and file imports beyond Apple Health:
 * - WHOOP, Fitbit, Withings and Oura: OAuth 2 connections (an admin registers an app with
 *   each and adds its client ID and secret in Site settings); readings sync hourly from
 *   the scheduled tasks. Tokens are encrypted with a key kept in storage/app.key.
 * - MyFitnessPal (no public API) and Google Fit (API being shut down): import their
 *   data export files. Health Connect on Android posts to the existing sync address.
 * All readings go through save_health_rows(), with an external id so nothing is duplicated.
 */

const CONNECTORS = [
    'whoop' => [
        'name' => 'WHOOP', 'auth' => 'https://api.prod.whoop.com/oauth/oauth2/auth', 'token' => 'https://api.prod.whoop.com/oauth/oauth2/token',
        'api' => 'https://api.prod.whoop.com/developer', 'scope' => 'offline read:recovery read:sleep read:body_measurement', 'style' => 'post',
        'register' => 'https://developer.whoop.com', 'gives' => 'Resting heart rate, heart rate variability, oxygen level, sleep, breathing rate and weight.',
    ],
    'fitbit' => [
        'name' => 'Fitbit', 'auth' => 'https://www.fitbit.com/oauth2/authorize', 'token' => 'https://api.fitbit.com/oauth2/token',
        'api' => 'https://api.fitbit.com', 'scope' => 'activity heartrate sleep weight oxygen_saturation', 'style' => 'basic', 'pkce' => true,
        'register' => 'https://dev.fitbit.com/apps/new', 'gives' => 'Steps, resting heart rate, weight, sleep, oxygen level and heart rate variability.',
    ],
    'withings' => [
        'name' => 'Withings', 'auth' => 'https://account.withings.com/oauth2_user/authorize2', 'token' => 'https://wbsapi.withings.net/v2/oauth2',
        'api' => 'https://wbsapi.withings.net', 'scope' => 'user.metrics,user.activity', 'style' => 'withings',
        'register' => 'https://developer.withings.com/dashboard/', 'gives' => 'Weight, blood pressure, pulse, oxygen level, temperature and steps from Withings scales, monitors and watches.',
    ],
    'oura' => [
        'name' => 'Oura', 'auth' => 'https://cloud.ouraring.com/oauth/authorize', 'token' => 'https://api.ouraring.com/oauth/token',
        'api' => 'https://api.ouraring.com', 'scope' => 'daily heartrate spo2 personal', 'style' => 'post',
        'register' => 'https://cloud.ouraring.com/oauth/applications', 'gives' => 'Sleep, steps, resting heart rate, heart rate variability, oxygen level and breathing rate.',
    ],
];
const SOURCE_LABELS = ['apple_health' => 'Apple Health', 'whoop' => 'WHOOP', 'fitbit' => 'Fitbit', 'withings' => 'Withings', 'oura' => 'Oura',
    'myfitnesspal' => 'MyFitnessPal', 'google_fit' => 'Google Fit', 'antenatal' => 'Antenatal check', 'nightscout' => 'Nightscout', 'bp_csv' => 'Monitor file'];

function reading_source_label(string $source): string
{
    return SOURCE_LABELS[$source] ?? ucfirst(str_replace('_', ' ', $source));
}

/** A connector's settings, with URL overrides from config (used for testing). */
function connector(string $provider): array
{
    $c = CONNECTORS[$provider];
    foreach (['auth', 'token', 'api'] as $k) {
        $c[$k] = (string) config("connectors.$provider.{$k}_url", $c[$k]);
    }
    $c['client_id'] = (string) config("connectors.$provider.client_id", '');
    $c['client_secret'] = (string) config("connectors.$provider.client_secret", '');
    return $c;
}

function connector_configured(string $provider): bool
{
    $c = connector($provider);
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/** The one address all services send people back to; register it with each. */
function connect_redirect_uri(): string
{
    return absolute_url('connect.php');
}

/* ---------- token encryption ---------- */

function app_key(): string
{
    $file = storage_path('app.key');
    $key = @file_get_contents($file);
    if ($key === false || strlen($key) !== 32) {
        $key = random_bytes(32);
        file_put_contents($file, $key, LOCK_EX);
        @chmod($file, 0600);
    }
    return $key;
}

function seal(string $plain): string
{
    $nonce = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    return base64_encode($nonce . $tag . $cipher);
}

function unseal(?string $sealed): ?string
{
    if ($sealed === null || $sealed === '') {
        return null;
    }
    $raw = base64_decode($sealed, true);
    $plain = $raw === false || strlen($raw) < 29 ? false
        : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/* ---------- OAuth ---------- */

/** Where to send someone to connect: returns the provider's sign-in URL. */
function connector_start(string $provider): string
{
    $c = connector($provider);
    $state = bin2hex(random_bytes(16));
    $_SESSION['connect'] = ['provider' => $provider, 'state' => $state, 'at' => time()];
    $params = ['response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => connect_redirect_uri(), 'scope' => $c['scope'], 'state' => $state];
    if (!empty($c['pkce'])) {
        $verifier = b64url_encode(random_bytes(48));
        $_SESSION['connect']['verifier'] = $verifier;
        $params += ['code_challenge' => b64url_encode(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256'];
    }
    return $c['auth'] . '?' . http_build_query($params);
}

/** Exchange a code, or refresh a token. @return array{access: string, refresh: ?string, expires: ?int, user: ?string} */
function connector_token(string $provider, array $grant): array
{
    $c = connector($provider);
    $headers = [];
    $form = $grant;
    switch ($c['style']) {
        case 'basic':
            $headers[] = 'Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']);
            break;
        case 'withings':
            $form = ['action' => 'requesttoken', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']] + $form;
            break;
        default:
            $form += ['client_id' => $c['client_id'], 'client_secret' => $c['client_secret']];
    }
    $data = http_json($c['token'], $form, $headers, $c['name']);
    if ($c['style'] === 'withings') {
        if ((int) ($data['status'] ?? -1) !== 0) {
            throw new RuntimeException("{$c['name']} refused the sign-in (status " . ($data['status'] ?? '?') . ').');
        }
        $data = (array) ($data['body'] ?? []);
    }
    if (empty($data['access_token'])) {
        throw new RuntimeException("{$c['name']} didn't send an access token.");
    }
    return ['access' => (string) $data['access_token'], 'refresh' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
        'expires' => isset($data['expires_in']) ? time() + (int) $data['expires_in'] : null,
        'user' => isset($data['user_id']) ? (string) $data['user_id'] : (isset($data['userid']) ? (string) $data['userid'] : null)];
}

/** Handle the return from the provider: save the connection. @return string the provider */
function connector_finish(int $userId, string $code, string $state): string
{
    $pending = $_SESSION['connect'] ?? null;
    unset($_SESSION['connect']);
    if (!$pending || $pending['at'] < time() - 900 || !hash_equals($pending['state'], $state)) {
        throw new RuntimeException('That took too long or came from somewhere else. Try connecting again.');
    }
    $provider = $pending['provider'];
    $grant = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => connect_redirect_uri()];
    if (!empty($pending['verifier'])) {
        $grant['code_verifier'] = $pending['verifier'];
    }
    $t = connector_token($provider, $grant);
    q('INSERT INTO connections (user_id, provider, access_token, refresh_token, expires_at, external_user) VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at),
                               external_user = VALUES(external_user), last_error = NULL',
        [$userId, $provider, seal($t['access']), $t['refresh'] ? seal($t['refresh']) : null, $t['expires'] ? gmdate('Y-m-d H:i:s', $t['expires']) : null, $t['user']]);
    return $provider;
}

/** A current access token for a connection, refreshing it if it has (nearly) expired. */
function connection_token(array $conn): string
{
    if ($conn['expires_at'] && strtotime($conn['expires_at'] . ' UTC') < time() + 120) {
        $refresh = unseal($conn['refresh_token']);
        if (!$refresh) {
            throw new RuntimeException('The connection has expired. Connect again.');
        }
        $t = connector_token($conn['provider'], ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        q('UPDATE connections SET access_token = ?, refresh_token = ?, expires_at = ? WHERE id = ?',
            [seal($t['access']), seal($t['refresh'] ?? $refresh), $t['expires'] ? gmdate('Y-m-d H:i:s', $t['expires']) : null, $conn['id']]);
        return $t['access'];
    }
    $token = unseal($conn['access_token']);
    if ($token === null) {
        throw new RuntimeException('The saved connection could not be read. Connect again.');
    }
    return $token;
}

/* ---------- syncing ---------- */

/** Fetch and save the latest readings for one connection. @return array save_health_rows() result */
function sync_connection(array $conn, ?int $days = null): array
{
    $user = q('SELECT * FROM users WHERE id = ?', [$conn['user_id']])->fetch();
    set_current_user($user);
    $tz = user_tz($user);
    // From the last sync (with two days' overlap: late-arriving data), or the last 30 days.
    $since = $days !== null ? time() - $days * 86400
        : ($conn['last_sync_at'] ? strtotime($conn['last_sync_at'] . ' UTC') - 2 * 86400 : time() - 30 * 86400);
    $since = max($since, time() - 90 * 86400);
    try {
        $token = connection_token($conn);
        $rows = ('sync_' . $conn['provider'])(connector($conn['provider']), $token, $since, $tz);
        $result = save_health_rows((int) $conn['user_id'], $rows, $conn['provider']);
        q('UPDATE connections SET last_sync_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?', [$conn['id']]);
        return $result;
    } catch (Throwable $e) {
        q('UPDATE connections SET last_sync_at = UTC_TIMESTAMP(), last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $conn['id']]);
        throw $e;
    }
}

/** The scheduled task: sync each connection about once an hour. */
function sync_connections(DateTimeImmutable $now): array
{
    $log = [];
    foreach (q("SELECT c.* FROM connections c JOIN users u ON u.id = c.user_id WHERE u.is_active = 1
                AND (c.last_sync_at IS NULL OR c.last_sync_at < ?)", [$now->modify('-55 minutes')->format('Y-m-d H:i:s')]) as $conn) {
        if ($conn['provider'] === 'nightscout') {
            try {
                $r = nightscout_sync($conn, ($conn['last_sync_at'] ? strtotime($conn['last_sync_at'] . ' UTC') : time() - 14 * 86400) - 3 * 3600);
                if ($r['added']) {
                    $log[] = "Nightscout for user {$conn['user_id']}: {$r['added']} new readings.";
                }
            } catch (Throwable $e) {
                q('UPDATE connections SET last_sync_at = UTC_TIMESTAMP(), last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $conn['id']]);
                $log[] = "Nightscout for user {$conn['user_id']} failed: " . $e->getMessage();
            }
            continue;
        }
        if (!connector_configured($conn['provider'])) {
            continue;
        }
        try {
            $r = sync_connection($conn);
            if ($r['added'] || $r['updated']) {
                $log[] = CONNECTORS[$conn['provider']]['name'] . " for user {$conn['user_id']}: {$r['added']} new, {$r['updated']} updated.";
            }
        } catch (Throwable $e) {
            $log[] = CONNECTORS[$conn['provider']]['name'] . " for user {$conn['user_id']} failed: " . $e->getMessage();
        }
    }
    return $log;
}

/** One reading row for save_health_rows(). $when: a unix time. */
function connector_row(string $provider, string $metric, array $values, int $when, string $id, ?string $context = null): array
{
    return ['metric' => $metric, 'values' => array_pad(array_map(fn ($v) => $v === null ? null : round((float) $v, 2), $values), 3, null),
        'context' => $context, 'recorded_at' => gmdate('Y-m-d H:i:s', $when), 'external_id' => sha1("$provider|$id"), 'replace' => true];
}

/** A local date at midday as a unix time: where daily values go. */
function local_noon(string $ymd, DateTimeZone $tz): int
{
    return (new DateTimeImmutable("$ymd 12:00", $tz))->getTimestamp();
}

function api_get(string $url, string $token, string $service): array
{
    return http_json($url, null, ['Authorization: Bearer ' . $token], $service);
}

function sync_whoop(array $c, string $token, int $since, DateTimeZone $tz): array
{
    $rows = [];
    $range = http_build_query(['start' => gmdate('Y-m-d\TH:i:s.000\Z', $since), 'end' => gmdate('Y-m-d\TH:i:s.000\Z'), 'limit' => 25]);
    $pages = function (string $path) use ($c, $token, $range): Generator {
        $next = null;
        for ($i = 0; $i < 20; $i++) {
            $data = api_get($c['api'] . $path . '?' . $range . ($next ? '&nextToken=' . urlencode($next) : ''), $token, 'WHOOP');
            yield from (array) ($data['records'] ?? []);
            if (!($next = $data['next_token'] ?? null)) {
                break;
            }
        }
    };
    foreach ($pages('/v2/recovery') as $r) {
        if (($r['score_state'] ?? '') !== 'SCORED' || empty($r['score'])) {
            continue;
        }
        $when = strtotime((string) $r['created_at']);
        $id = (string) ($r['cycle_id'] ?? $r['sleep_id'] ?? $when);
        $s = $r['score'];
        if (!empty($s['resting_heart_rate'])) {
            $rows[] = connector_row('whoop', 'heart_rate', [$s['resting_heart_rate']], $when, "rhr|$id", 'Resting');
        }
        if (!empty($s['hrv_rmssd_milli'])) {
            $rows[] = connector_row('whoop', 'hrv', [$s['hrv_rmssd_milli']], $when, "hrv|$id");
        }
        if (!empty($s['spo2_percentage'])) {
            $rows[] = connector_row('whoop', 'oxygen_saturation', [$s['spo2_percentage']], $when, "spo2|$id");
        }
    }
    foreach ($pages('/v2/activity/sleep') as $sl) {
        if (!empty($sl['nap']) || ($sl['score_state'] ?? '') !== 'SCORED' || empty($sl['score']['stage_summary'])) {
            continue;
        }
        $st = $sl['score']['stage_summary'];
        $when = strtotime((string) $sl['end']);
        $hours = ((int) ($st['total_in_bed_time_milli'] ?? 0) - (int) ($st['total_awake_time_milli'] ?? 0)) / 3600000;
        if ($hours > 0) {
            $rows[] = connector_row('whoop', 'sleep', [$hours], $when, 'sleep|' . $sl['id']);
        }
        if (!empty($sl['score']['respiratory_rate'])) {
            $rows[] = connector_row('whoop', 'respiratory_rate', [$sl['score']['respiratory_rate']], $when, 'rr|' . $sl['id']);
        }
    }
    $body = api_get($c['api'] . '/v2/user/measurement/body', $token, 'WHOOP');
    if (!empty($body['weight_kilogram'])) {
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $rows[] = connector_row('whoop', 'weight', [$body['weight_kilogram']], local_noon($today, $tz), "weight|$today");
    }
    return $rows;
}

function sync_fitbit(array $c, string $token, int $since, DateTimeZone $tz): array
{
    $rows = [];
    $start = (new DateTimeImmutable("@$since"))->setTimezone($tz)->format('Y-m-d');
    $end = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $get = fn (string $path) => api_get($c['api'] . $path, $token, 'Fitbit');
    foreach ((array) ($get("/1/user/-/activities/steps/date/$start/$end.json")['activities-steps'] ?? []) as $d) {
        if ((int) $d['value'] > 0) {
            $rows[] = connector_row('fitbit', 'steps', [$d['value']], local_noon($d['dateTime'], $tz), 'steps|' . $d['dateTime']);
        }
    }
    foreach ((array) ($get("/1/user/-/activities/heart/date/$start/$end.json")['activities-heart'] ?? []) as $d) {
        if (!empty($d['value']['restingHeartRate'])) {
            $rows[] = connector_row('fitbit', 'heart_rate', [$d['value']['restingHeartRate']], local_noon($d['dateTime'], $tz), 'rhr|' . $d['dateTime'], 'Resting');
        }
    }
    // Weight logs are limited to 31 days per request.
    $wStart = max($start, (new DateTimeImmutable($end))->modify('-30 days')->format('Y-m-d'));
    foreach ((array) ($get("/1/user/-/body/log/weight/date/$wStart/$end.json")['weight'] ?? []) as $w) {
        $rows[] = connector_row('fitbit', 'weight', [$w['weight']], (new DateTimeImmutable($w['date'] . ' ' . ($w['time'] ?? '08:00:00'), $tz))->getTimestamp(), 'weight|' . ($w['logId'] ?? $w['date']));
    }
    foreach ((array) ($get("/1.2/user/-/sleep/date/$start/$end.json")['sleep'] ?? []) as $s) {
        if (!empty($s['isMainSleep']) && !empty($s['minutesAsleep'])) {
            $rows[] = connector_row('fitbit', 'sleep', [$s['minutesAsleep'] / 60], (new DateTimeImmutable((string) $s['endTime'], $tz))->getTimestamp(), 'sleep|' . ($s['logId'] ?? $s['dateOfSleep']));
        }
    }
    foreach ((array) $get("/1/user/-/spo2/date/$start/$end.json") as $d) {
        if (is_array($d) && !empty($d['value']['avg'])) {
            $rows[] = connector_row('fitbit', 'oxygen_saturation', [$d['value']['avg']], local_noon($d['dateTime'], $tz), 'spo2|' . $d['dateTime']);
        }
    }
    foreach ((array) ($get("/1/user/-/hrv/date/$start/$end.json")['hrv'] ?? []) as $d) {
        if (!empty($d['value']['dailyRmssd'])) {
            $rows[] = connector_row('fitbit', 'hrv', [$d['value']['dailyRmssd']], local_noon($d['dateTime'], $tz), 'hrv|' . $d['dateTime']);
        }
    }
    return $rows;
}

function sync_withings(array $c, string $token, int $since, DateTimeZone $tz): array
{
    $rows = [];
    $call = function (string $path, array $form) use ($c, $token): array {
        $data = http_json($c['api'] . $path, $form, ['Authorization: Bearer ' . $token], 'Withings');
        if ((int) ($data['status'] ?? -1) !== 0) {
            throw new RuntimeException('Withings returned status ' . ($data['status'] ?? '?') . '.');
        }
        return (array) ($data['body'] ?? []);
    };
    // Types: 1 weight, 9 diastolic, 10 systolic, 11 pulse, 54 SpO2, 71 body temperature.
    $body = $call('/measure', ['action' => 'getmeas', 'meastypes' => '1,9,10,11,54,71', 'category' => 1, 'startdate' => $since, 'enddate' => time()]);
    foreach ((array) ($body['measuregrps'] ?? []) as $g) {
        $v = [];
        foreach ((array) $g['measures'] as $m) {
            $v[(int) $m['type']] = (float) $m['value'] * 10 ** (int) $m['unit'];
        }
        $when = (int) $g['date'];
        $id = (string) $g['grpid'];
        if (isset($v[1])) {
            $rows[] = connector_row('withings', 'weight', [$v[1]], $when, "weight|$id");
        }
        if (isset($v[10], $v[9])) {
            $rows[] = connector_row('withings', 'blood_pressure', [$v[10], $v[9], $v[11] ?? null], $when, "bp|$id");
        } elseif (isset($v[11])) {
            $rows[] = connector_row('withings', 'heart_rate', [$v[11]], $when, "pulse|$id");
        }
        if (isset($v[54])) {
            $rows[] = connector_row('withings', 'oxygen_saturation', [$v[54]], $when, "spo2|$id");
        }
        if (isset($v[71])) {
            $rows[] = connector_row('withings', 'temperature', [$v[71]], $when, "temp|$id");
        }
    }
    $act = $call('/v2/measure', ['action' => 'getactivity', 'startdateymd' => (new DateTimeImmutable("@$since"))->setTimezone($tz)->format('Y-m-d'),
        'enddateymd' => (new DateTimeImmutable('now', $tz))->format('Y-m-d'), 'data_fields' => 'steps']);
    foreach ((array) ($act['activities'] ?? []) as $a) {
        if ((int) ($a['steps'] ?? 0) > 0) {
            $rows[] = connector_row('withings', 'steps', [$a['steps']], local_noon($a['date'], $tz), 'steps|' . $a['date']);
        }
    }
    return $rows;
}

function sync_oura(array $c, string $token, int $since, DateTimeZone $tz): array
{
    $rows = [];
    $q = http_build_query(['start_date' => (new DateTimeImmutable("@$since"))->setTimezone($tz)->format('Y-m-d'),
        'end_date' => (new DateTimeImmutable('tomorrow', $tz))->format('Y-m-d')]);
    $get = fn (string $what) => (array) (api_get($c['api'] . "/v2/usercollection/$what?$q", $token, 'Oura')['data'] ?? []);
    foreach ($get('sleep') as $s) {
        if (($s['type'] ?? 'long_sleep') !== 'long_sleep' || empty($s['bedtime_end'])) {
            continue;
        }
        $when = strtotime((string) $s['bedtime_end']);
        $id = (string) $s['id'];
        if (!empty($s['total_sleep_duration'])) {
            $rows[] = connector_row('oura', 'sleep', [$s['total_sleep_duration'] / 3600], $when, "sleep|$id");
        }
        if (!empty($s['average_hrv'])) {
            $rows[] = connector_row('oura', 'hrv', [$s['average_hrv']], $when, "hrv|$id");
        }
        if (!empty($s['lowest_heart_rate'])) {
            $rows[] = connector_row('oura', 'heart_rate', [$s['lowest_heart_rate']], $when, "rhr|$id", 'Resting');
        }
        if (!empty($s['average_breath'])) {
            $rows[] = connector_row('oura', 'respiratory_rate', [$s['average_breath']], $when, "rr|$id");
        }
    }
    foreach ($get('daily_activity') as $d) {
        if ((int) ($d['steps'] ?? 0) > 0) {
            $rows[] = connector_row('oura', 'steps', [$d['steps']], local_noon($d['day'], $tz), 'steps|' . $d['day']);
        }
    }
    foreach ($get('daily_spo2') as $d) {
        if (!empty($d['spo2_percentage']['average'])) {
            $rows[] = connector_row('oura', 'oxygen_saturation', [$d['spo2_percentage']['average']], local_noon($d['day'], $tz), 'spo2|' . $d['day']);
        }
    }
    return $rows;
}

/* ---------- file imports: MyFitnessPal and Google Fit ---------- */

/** CSV files to read from an upload: the file itself, or the matching files inside a zip. @return array<string, string> name => contents */
function upload_csv_files(string $path, string $originalName, string $pattern): array
{
    if (str_ends_with(mb_strtolower($originalName), '.zip')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("That zip file couldn't be opened.");
        }
        $maxBytes = 50 * 1024 * 1024;
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match($pattern, basename($name))) {
                continue;
            }
            // Read the decompressed stream with a hard cap, rather than trusting the zip's own
            // (attacker-controlled) declared size, which getFromIndex() won't enforce.
            $stream = $zip->getStreamIndex($i);
            if (!$stream) {
                continue;
            }
            $data = '';
            while (!feof($stream) && strlen($data) <= $maxBytes) {
                $data .= fread($stream, 1024 * 1024);
            }
            fclose($stream);
            if (strlen($data) <= $maxBytes) {
                $out[$name] = $data;
            }
        }
        $zip->close();
        return $out;
    }
    return [$originalName => (string) file_get_contents($path)];
}

/** Rows of a CSV as header => value arrays (headers lower-cased and trimmed). */
function csv_records(string $csv): array
{
    $lines = preg_split('/\R/', preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    $header = null;
    $out = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        if ($header === null) {
            $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $cells);
            continue;
        }
        $out[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
    }
    return $out;
}

/** A date from an export: 2024-01-31, 31/01/2024 or 01/31/2024 (as US if the first part can't be a day-first date). */
function export_date(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
        [$a, $b] = [(int) $m[1], (int) $m[2]];
        [$day, $month] = $b > 12 ? [$b, $a] : [$a, $b];
        return checkdate($month, $day, (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $month, $day) : null;
    }
    return null;
}

/**
 * MyFitnessPal's data export (Settings > Export data): a zip of CSVs, or one CSV.
 * Weight from the measurement summary; calories eaten per day from the nutrition summary.
 */
function import_myfitnesspal(int $userId, string $path, string $name, string $weightUnit, DateTimeZone $tz): array
{
    $files = upload_csv_files($path, $name, '/\.csv$/i');
    $rows = [];
    $calories = [];
    foreach ($files as $file => $csv) {
        foreach (csv_records($csv) as $r) {
            $day = export_date((string) ($r['date'] ?? ''));
            if (!$day) {
                continue;
            }
            if (isset($r['weight']) && is_numeric($r['weight']) && (float) $r['weight'] > 0) {
                $kg = (float) $r['weight'] * ($weightUnit === 'lb' ? 0.45359237 : ($weightUnit === 'st' ? 6.35029318 : 1));
                $rows[] = connector_row('myfitnesspal', 'weight', [$kg], local_noon($day, $tz), "weight|$day");
            }
            if (isset($r['calories']) && is_numeric($r['calories']) && isset($r['meal'])) {
                $calories[$day] = ($calories[$day] ?? 0) + (float) $r['calories'];
            }
        }
    }
    foreach ($calories as $day => $kcal) {
        $rows[] = connector_row('myfitnesspal', 'calories', [round($kcal)], local_noon($day, $tz), "calories|$day");
    }
    if (!$rows) {
        throw new RuntimeException("No weight or calories found. Upload the zip from MyFitnessPal's Export data, or its Measurement or Nutrition summary CSV.");
    }
    return save_health_rows($userId, $rows, 'myfitnesspal');
}

/** Google Fit from Google Takeout: "Daily activity metrics.csv" (or the Takeout zip). Steps and weight per day. */
function import_google_fit(int $userId, string $path, string $name, DateTimeZone $tz): array
{
    $files = upload_csv_files($path, $name, '/^Daily activity metrics\.csv$/i');
    $rows = [];
    foreach ($files as $csv) {
        foreach (csv_records($csv) as $r) {
            $day = export_date((string) ($r['date'] ?? ''));
            if (!$day) {
                continue;
            }
            if (is_numeric($r['step count'] ?? '') && (int) $r['step count'] > 0) {
                $rows[] = connector_row('google_fit', 'steps', [(int) $r['step count']], local_noon($day, $tz), "steps|$day");
            }
            if (is_numeric($r['average weight (kg)'] ?? '') && (float) $r['average weight (kg)'] > 0) {
                $rows[] = connector_row('google_fit', 'weight', [(float) $r['average weight (kg)']], local_noon($day, $tz), "weight|$day");
            }
        }
    }
    if (!$rows) {
        throw new RuntimeException('No steps or weight found. Upload "Daily activity metrics.csv" from Takeout (Fit > Daily activity metrics), or the whole Takeout zip.');
    }
    return save_health_rows($userId, $rows, 'google_fit');
}
