<?php
declare(strict_types=1);

/*
 * Weather for the headache diary (Migraine): air pressure, temperature and humidity where
 * someone lives, to compare with their headache days. From Open-Meteo (open-meteo.com: free,
 * no key or account). Only a town's coordinates, rounded to about 10 km, are sent: nothing
 * about the person. Site admins can switch it off (app.weather).
 * - weather_places: the town chosen; weather_days: one row a day (the last year and the next 2 days).
 * - Refreshed by the scheduled tasks every 6 hours; a year's history is filled in when a town is chosen.
 * - A big fall: the day's average pressure 5 hPa or more below the day before's.
 */

const WEATHER_FALL = 5.0;       // hPa lower than the day before (daily averages): a big fall in pressure
const WEATHER_CONDITIONS = [
    'fall'  => 'Pressure 5 hPa or more lower than the day before',
    'low'   => 'Low pressure (under 1000 hPa)',
    'swing' => 'Temperature 6°C or more different from the day before',
    'hot'   => 'Hot (25°C or more)',
    'humid' => 'Very humid (85% or more on average)',
];

function weather_enabled(): bool
{
    return (bool) config('app.weather', true);
}

function weather_place(int $userId): ?array
{
    return q('SELECT * FROM weather_places WHERE user_id = ?', [$userId])->fetch() ?: null;
}

/** Towns matching a search: [name, area, country, lat, lon]. */
function weather_search(string $query): array
{
    $data = http_json('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name' => $query, 'count' => 6, 'language' => 'en', 'format' => 'json']),
        null, [], 'the weather service');
    $places = [];
    foreach ((array) ($data['results'] ?? []) as $r) {
        if (isset($r['latitude'], $r['longitude'], $r['name'])) {
            $places[] = ['name' => (string) $r['name'], 'area' => (string) ($r['admin1'] ?? ''), 'country' => (string) ($r['country'] ?? ''),
                'lat' => round((float) $r['latitude'], 1), 'lon' => round((float) $r['longitude'], 1)];
        }
    }
    return $places;
}

/**
 * Fetch and store the weather for a place: the last week and the next 2 days, plus anything
 * missing since the last fetch (or the whole last year, with $history) from the archive.
 * @return int days stored
 */
function weather_fetch(array $place, bool $history = false): int
{
    // The weather at home, in home time (not a trip's time zone).
    $tz = home_tz(q('SELECT timezone FROM users WHERE id = ?', [$place['user_id']])->fetch() ?: null);
    $params = ['latitude' => $place['latitude'], 'longitude' => $place['longitude'], 'timezone' => $tz->getName(),
        'hourly' => 'pressure_msl,relative_humidity_2m', 'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum'];
    $today = new DateTimeImmutable('today', $tz);
    $sets = [];
    // The archive is complete up to about yesterday; the forecast service only has the last week or so.
    $last = $history ? null : q('SELECT MAX(day) FROM weather_days WHERE user_id = ? AND forecast = 0', [$place['user_id']])->fetchColumn();
    $from = max($last ? (new DateTimeImmutable((string) $last))->modify('-1 day') : $today->modify('-365 days'), $today->modify('-365 days'));
    if ($from < $today->modify('-6 days')) {
        $sets[] = http_json('https://archive-api.open-meteo.com/v1/archive?' . http_build_query($params + [
            'start_date' => $from->format('Y-m-d'), 'end_date' => $today->modify('-2 days')->format('Y-m-d')]), null, [], 'the weather service');
    }
    $sets[] = http_json('https://api.open-meteo.com/v1/forecast?' . http_build_query($params + ['past_days' => 7, 'forecast_days' => 3]), null, [], 'the weather service');
    $todayYmd = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $stored = 0;
    foreach ($sets as $data) {
        foreach (weather_days_from($data) as $day => $w) {
            q('INSERT INTO weather_days (user_id, day, pressure, temp_max, temp_min, humidity, rain, forecast) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE pressure = VALUES(pressure), temp_max = VALUES(temp_max), temp_min = VALUES(temp_min),
                   humidity = VALUES(humidity), rain = VALUES(rain), forecast = VALUES(forecast)',
                [$place['user_id'], $day, $w['pressure'], $w['temp_max'], $w['temp_min'], $w['humidity'], $w['rain'], $day > $todayYmd ? 1 : 0]);
            $stored++;
        }
    }
    // Pollen (Europe only; elsewhere the counts are empty): the last week and the next 3 days.
    try {
        $air = http_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . http_build_query(['latitude' => $place['latitude'], 'longitude' => $place['longitude'],
            'timezone' => $tz->getName(), 'past_days' => 7, 'forecast_days' => 4,
            'hourly' => 'alder_pollen,birch_pollen,olive_pollen,grass_pollen,mugwort_pollen,ragweed_pollen']), null, [], 'the pollen service');
        foreach (pollen_days_from($air) as $day => $pl) {
            q('UPDATE weather_days SET pollen_grass = ?, pollen_tree = ?, pollen_weed = ? WHERE user_id = ? AND day = ?',
                [$pl['grass'], $pl['tree'], $pl['weed'], $place['user_id'], $day]);
        }
    } catch (RuntimeException $e) {
        error_log('Pillbox pollen: ' . $e->getMessage()); // weather still works without it
    }
    // How far the average pressure fell from the day before (negative: it rose).
    q('UPDATE weather_days w JOIN weather_days p ON p.user_id = w.user_id AND p.day = w.day - INTERVAL 1 DAY
       SET w.pressure_fall = p.pressure - w.pressure WHERE w.user_id = ?', [$place['user_id']]);
    q('UPDATE weather_places SET fetched_at = UTC_TIMESTAMP(), last_error = NULL WHERE user_id = ?', [$place['user_id']]);
    return $stored;
}

/**
 * Daily figures from an Open-Meteo response: mean pressure, temperatures, mean humidity and rain.
 * Days with missing hours are left out.
 * @return array<string, array{pressure: float, temp_max: ?float, temp_min: ?float, humidity: ?float, rain: ?float}>
 */
function weather_days_from(array $data): array
{
    $times = (array) ($data['hourly']['time'] ?? []);
    $pressure = (array) ($data['hourly']['pressure_msl'] ?? []);
    $humidity = (array) ($data['hourly']['relative_humidity_2m'] ?? []);
    $byDay = [];
    foreach ($times as $i => $t) {
        $byDay[substr((string) $t, 0, 10)][] = $i;
    }
    $daily = [];
    foreach ((array) ($data['daily']['time'] ?? []) as $i => $d) {
        $daily[$d] = $i;
    }
    $days = [];
    foreach ($byDay as $day => $hours) {
        $p = array_filter(array_map(fn ($i) => $pressure[$i] ?? null, $hours), 'is_numeric');
        if (count($p) < 20) {
            continue;
        }
        $hum = array_filter(array_map(fn ($i) => $humidity[$i] ?? null, $hours), 'is_numeric');
        $di = $daily[$day] ?? null;
        $val = fn (string $k) => $di !== null && is_numeric($data['daily'][$k][$di] ?? null) ? round((float) $data['daily'][$k][$di], 1) : null;
        $days[$day] = ['pressure' => round(array_sum($p) / count($p), 1),
            'temp_max' => $val('temperature_2m_max'), 'temp_min' => $val('temperature_2m_min'),
            'humidity' => $hum ? round(array_sum($hum) / count($hum)) : null, 'rain' => $val('precipitation_sum')];
    }
    return $days;
}

/**
 * Daily highest pollen counts (grains/m³) from an Open-Meteo air quality response; null where
 * there's no data (outside Europe, or out of season for some).
 * @return array<string, array{grass: ?int, tree: ?int, weed: ?int}>
 */
function pollen_days_from(array $data): array
{
    $groups = ['grass' => ['grass_pollen'], 'tree' => ['alder_pollen', 'birch_pollen', 'olive_pollen'], 'weed' => ['mugwort_pollen', 'ragweed_pollen']];
    $days = [];
    foreach ((array) ($data['hourly']['time'] ?? []) as $i => $t) {
        $day = substr((string) $t, 0, 10);
        foreach ($groups as $g => $keys) {
            foreach ($keys as $k) {
                $v = $data['hourly'][$k][$i] ?? null;
                if (is_numeric($v)) {
                    $days[$day][$g] = max($days[$day][$g] ?? 0, (int) round((float) $v));
                }
            }
        }
    }
    return array_map(fn ($d) => ['grass' => $d['grass'] ?? null, 'tree' => $d['tree'] ?? null, 'weed' => $d['weed'] ?? null], $days);
}

/** Stored weather between two local dates, by day. */
function weather_between(int $userId, string $from, string $to): array
{
    $days = [];
    foreach (q('SELECT * FROM weather_days WHERE user_id = ? AND day BETWEEN ? AND ? ORDER BY day', [$userId, $from, $to]) as $w) {
        $days[$w['day']] = $w;
    }
    return $days;
}

/** Which of WEATHER_CONDITIONS a day meets ($prev: the day before, for temperature swings). */
function weather_conditions(array $w, ?array $prev): array
{
    $on = [];
    if ($w['pressure_fall'] !== null && (float) $w['pressure_fall'] >= WEATHER_FALL) {
        $on[] = 'fall';
    }
    if ((float) $w['pressure'] < 1000) {
        $on[] = 'low';
    }
    if ($prev && $w['temp_max'] !== null && $prev['temp_max'] !== null && abs((float) $w['temp_max'] - (float) $prev['temp_max']) >= 6) {
        $on[] = 'swing';
    }
    if ($w['temp_max'] !== null && (float) $w['temp_max'] >= 25) {
        $on[] = 'hot';
    }
    if ($w['humidity'] !== null && (float) $w['humidity'] >= 85) {
        $on[] = 'humid';
    }
    return $on;
}

/**
 * Headache days compared with the weather, over the stored past days.
 * @return array{days: int, headache_days: int, rows: array<string, array{days: int, with: int, rate: float, other_rate: float, likelier: bool}>}
 */
function weather_patterns(int $userId, DateTimeImmutable $today): array
{
    $weather = weather_between($userId, $today->modify('-365 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'));
    $headache = [];
    if ($weather) {
        foreach (q('SELECT started_at FROM headache_episodes WHERE user_id = ? AND started_at >= ?', [$userId, gmdate('Y-m-d H:i:s', strtotime(array_key_first($weather)) - 86400)]) as $e) {
            $headache[local_time($e['started_at'], 'Y-m-d')] = true;
        }
    }
    $counts = array_fill_keys(array_keys(WEATHER_CONDITIONS), ['days' => 0, 'with' => 0]);
    $prev = null;
    $withHeadache = 0;
    foreach ($weather as $day => $w) {
        $had = isset($headache[$day]);
        $withHeadache += $had ? 1 : 0;
        foreach (weather_conditions($w, $prev && $prev['day'] === (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d') ? $prev : null) as $c) {
            $counts[$c]['days']++;
            $counts[$c]['with'] += $had ? 1 : 0;
        }
        $prev = $w;
    }
    $total = count($weather);
    $rows = [];
    foreach ($counts as $c => $n) {
        $otherDays = $total - $n['days'];
        if ($n['days'] < 3 || $otherDays < 3) {
            continue;
        }
        $rate = $n['with'] / $n['days'];
        $other = ($withHeadache - $n['with']) / $otherDays;
        $rows[$c] = $n + ['rate' => $rate, 'other_rate' => $other, 'likelier' => $n['with'] >= 3 && $rate >= max(1.5 * $other, $other + 0.1)];
    }
    return ['days' => $total, 'headache_days' => $withHeadache, 'rows' => $rows];
}

/** A big pressure fall forecast for today or tomorrow: [day, hPa] or null. */
function weather_fall_ahead(int $userId, DateTimeImmutable $today): ?array
{
    foreach (weather_between($userId, $today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d')) as $day => $w) {
        if ($w['pressure_fall'] !== null && (float) $w['pressure_fall'] >= WEATHER_FALL) {
            return [$day, (float) $w['pressure_fall']];
        }
    }
    return null;
}

/** Short weather text for a diary entry: "1004 hPa, fell 6 · 18°C". */
function weather_note(?array $w): string
{
    if (!$w) {
        return '';
    }
    $parts = [num(round((float) $w['pressure'])) . ' hPa' . ($w['pressure_fall'] !== null && (float) $w['pressure_fall'] >= 2 ? ', fell ' . num(round((float) $w['pressure_fall'])) : '')];
    if ($w['temp_max'] !== null) {
        $parts[] = num(round((float) $w['temp_max'])) . '°C';
    }
    return implode(' · ', $parts);
}

/** Scheduled task: refresh everyone's weather every 6 hours. */
function sync_weather(DateTimeImmutable $now): array
{
    if (!weather_enabled()) {
        return [];
    }
    $log = [];
    foreach (q("SELECT p.* FROM weather_places p JOIN users u ON u.id = p.user_id WHERE u.is_active = 1 AND u.is_demo = 0
                AND (p.fetched_at IS NULL OR p.fetched_at < ?) LIMIT 50", [$now->modify('-6 hours')->format('Y-m-d H:i:s')]) as $place) {
        try {
            weather_fetch($place, !q('SELECT 1 FROM weather_days WHERE user_id = ? LIMIT 1', [$place['user_id']])->fetch());
        } catch (Throwable $e) {
            q('UPDATE weather_places SET fetched_at = UTC_TIMESTAMP(), last_error = ? WHERE user_id = ?', [mb_substr($e->getMessage(), 0, 300), $place['user_id']]);
            $log[] = "Weather for user {$place['user_id']} failed: " . $e->getMessage();
        }
    }
    // Only the last year or so is compared.
    q('DELETE FROM weather_days WHERE day < ?', [$now->modify('-400 days')->format('Y-m-d')]);
    return $log;
}

// Pages with a town form: action prefix => where to go back to.
const WEATHER_FORMS = ['mg' => 'care.php?area=migraine#mg-weather', 'al' => 'care.php?area=allergies#al-pollen'];

/** Town actions on the Migraine (mg_weather_*) and Allergies (al_weather_*) pages. */
function weather_handle_post(array $user): never
{
    $uid = (int) $user['id'];
    [$prefix, $action] = array_pad(explode('_weather_', post('action'), 2), 2, '');
    switch ($action) {
        case 'search':
            $query = trim(mb_substr(post('place'), 0, 80));
            try {
                $_SESSION['weather_matches'] = $query === '' ? [] : weather_search($query);
                if ($query !== '' && !$_SESSION['weather_matches']) {
                    flash('error', "Couldn't find \"$query\". Try the nearest town or city.");
                }
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
            break;
        case 'set':
            // The chosen town, as "lat|lon|name" (from the search results shown).
            [$lat, $lon, $name] = array_pad(explode('|', post('match'), 3), 3, '');
            $match = is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180 && trim($name) !== ''
                ? ['name' => trim(mb_substr($name, 0, 120)), 'area' => '', 'lat' => round((float) $lat, 1), 'lon' => round((float) $lon, 1)] : null;
            if ($match) {
                $label = trim($match['name'] . ($match['area'] && $match['area'] !== $match['name'] ? ', ' . $match['area'] : ''));
                $old = weather_place($uid);
                if ($old && ((float) $old['latitude'] !== $match['lat'] || (float) $old['longitude'] !== $match['lon'])) {
                    q('DELETE FROM weather_days WHERE user_id = ?', [$uid]);
                }
                q('INSERT INTO weather_places (user_id, name, latitude, longitude) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE name = VALUES(name), latitude = VALUES(latitude), longitude = VALUES(longitude), fetched_at = NULL, last_error = NULL',
                    [$uid, mb_substr($label, 0, 120), $match['lat'], $match['lon']]);
                try {
                    weather_fetch(weather_place($uid), true);
                    flash('success', "Added $label: weather (including the last year) and pollen counts.");
                } catch (RuntimeException $e) {
                    flash('warning', "Saved $label, but the weather couldn't be fetched just now (" . $e->getMessage() . '). Pillbox will try again later.');
                }
            }
            break;
        case 'off':
            q('DELETE FROM weather_places WHERE user_id = ?', [$uid]);
            q('DELETE FROM weather_days WHERE user_id = ?', [$uid]);
            flash('success', 'Weather and pollen turned off, and the data deleted.');
            break;
    }
    redirect(WEATHER_FORMS[$prefix] ?? 'care.php');
}

/** The Weather card on the Migraine page. */
function weather_section(array $user, DateTimeImmutable $today, bool $canEdit): void
{
    if (!weather_enabled()) {
        return;
    }
    $uid = (int) $user['id'];
    $place = weather_place($uid);
    $patterns = $place ? weather_patterns($uid, $today) : null;
    $ahead = $place ? weather_fall_ahead($uid, $today) : null;
    ?>
<section class="card" id="mg-weather">
    <h2>Weather <?= help_link('migraine', 'weather', 'weather and headaches') ?></h2>
    <?php if (!$place): ?>
    <p class="small">Some people find weather changes, especially falling air pressure, bring on headaches. Add your town and Pillbox will compare your headache days with the weather there,
        including the last year, so you can see if there's a pattern.</p>
    <p class="muted small">Only the town's location (to about 10 km) is sent to Open-Meteo, a free weather service: nothing about you or your headaches.</p>
    <?php else: ?>
        <?php if ($ahead): ?>
    <p class="flash flash-info">Air pressure is forecast to fall by about <?= h(num(round($ahead[1]))) ?> hPa <?= $ahead[0] === $today->format('Y-m-d') ? 'today' : 'tomorrow' ?>.
        <?= isset($patterns['rows']['fall']) && $patterns['rows']['fall']['likelier'] ? 'Your headaches have been more likely on days like this, so keep your usual treatment to hand.' : '' ?></p>
        <?php endif; ?>
    <p class="small">Weather for <strong><?= h($place['name']) ?></strong><?= $place['fetched_at'] ? ', updated ' . h(local_time($place['fetched_at'], 'j M H:i')) : '' ?>.
        <?php if ($place['last_error']): ?><span class="warn">The last update failed: <?= h($place['last_error']) ?></span><?php endif; ?></p>
        <?php if ($patterns['days'] < 30 || $patterns['headache_days'] < 5): ?>
    <p class="small">Not enough to compare yet: <?= $patterns['days'] ?> days of weather and <?= $patterns['headache_days'] ?> headache days in that time. Keep logging your headaches.</p>
        <?php elseif ($patterns['rows']): ?>
    <div class="table-wrap"><table><thead><tr><th>Weather</th><th class="num">Days</th><th class="num">Headache on those days</th><th class="num">On other days</th></tr></thead><tbody>
        <?php foreach ($patterns['rows'] as $c => $r): ?><tr><td><?= h(WEATHER_CONDITIONS[$c]) ?><?= $r['likelier'] ? ' <strong>&#9650; more likely</strong>' : '' ?></td>
            <td class="num"><?= $r['days'] ?></td><td class="num"><?= round($r['rate'] * 100) ?>%</td><td class="num"><?= round($r['other_rate'] * 100) ?>%</td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="muted small">Over the last <?= $patterns['days'] ?> days, with <?= $patterns['headache_days'] ?> headache days. A pattern here doesn't prove the weather causes your headaches,
        and you can't change the weather, but knowing can help you plan and have treatment ready.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($canEdit) { weather_place_form($place, 'mg'); } ?>
</section>
    <?php
}

/** Find / choose / change the town, and turn it off ($prefix: the page's action prefix, see WEATHER_FORMS). */
function weather_place_form(?array $place, string $prefix): void
{
    $matches = $_SESSION['weather_matches'] ?? null;
    unset($_SESSION['weather_matches']); // shown once
    if (is_array($matches) && $matches): ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($prefix) ?>_weather_set">
        <fieldset><legend>Which one?</legend>
            <?php foreach ($matches as $i => $m): $label = implode(', ', array_filter([$m['name'], $m['area'] !== $m['name'] ? $m['area'] : ''])); ?>
            <label class="check"><input type="radio" name="match" value="<?= h($m['lat'] . '|' . $m['lon'] . '|' . $label) ?>"<?= checked($i === 0) ?>> <?= h(implode(', ', array_filter([$label, $m['country']]))) ?></label><?php endforeach; ?>
        </fieldset>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Use this place</button></span>
    </form>
    <?php else: ?>
    <details class="q-edit"<?= $place ? '' : ' open' ?>><summary><?= $place ? 'Change town' : 'Add your town' ?></summary>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($prefix) ?>_weather_search">
            <label>Town or city <input name="place" maxlength="80" required autocomplete="address-level2" value="<?= h($place ? explode(',', $place['name'])[0] : '') ?>"></label>
            <button class="btn btn-small btn-primary" type="submit">Find</button></form>
    </details>
    <?php endif; ?>
    <?php if ($place): ?>
    <form method="post" data-confirm="Turn off weather and pollen, and delete the weather data?"><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($prefix) ?>_weather_off"><button class="btn-link danger small" type="submit">Turn off weather and pollen</button></form>
    <?php endif;
}
