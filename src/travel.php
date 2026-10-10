<?php
declare(strict_types=1);

/*
 * Travel mode: how scheduled dose times follow someone across time zones.
 *
 * Every dose belongs to a date on the person's home calendar. Outside a trip it's due
 * at its clock time at home. During a trip the plan's mode decides the moment:
 *   local   - same clock time where they are (08:00 in Tokyo)
 *   home    - stay on home time (08:00 London = 16:00 Tokyo)
 *   gradual - move from home time towards local time by a few hours a day, and back
 *             again the same way after returning
 * While a trip is under way, Pillbox shows everything in the destination's time zone.
 */

const TRAVEL_MODES = [
    'local'   => ['Switch to local time', 'Take doses at the same clock time as at home, but in local time (e.g. 08:00 in Tokyo). Simplest for most medicines.'],
    'home'    => ['Stay on home time', 'Keep taking doses at exactly the same moments as at home (e.g. 08:00 London = 16:00 Tokyo). Keeps the same gap between doses.'],
    'gradual' => ['Shift gradually', 'Move dose times a few hours a day until they match local time, then back again when you return. Useful for time-critical medicines.'],
];

function home_tz(?array $user): DateTimeZone
{
    try {
        return new DateTimeZone($user['timezone'] ?? config('app.default_timezone'));
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

/** All of a user's travel plans, cached per request. */
function user_travel_plans(int $userId, bool $refresh = false): array
{
    static $cache = [];
    if ($refresh || !isset($cache[$userId])) {
        $cache[$userId] = q('SELECT * FROM travel_plans WHERE user_id = ? ORDER BY start_date', [$userId])->fetchAll();
    }
    return $cache[$userId];
}

/** The trip under way today (by the home calendar), if any. */
function active_trip(?array $user): ?array
{
    if (!$user || empty($user['id'])) {
        return null;
    }
    $today = (new DateTimeImmutable('now', home_tz($user)))->format('Y-m-d');
    foreach (user_travel_plans((int) $user['id']) as $plan) {
        if ($plan['start_date'] <= $today && $plan['end_date'] >= $today) {
            return $plan;
        }
    }
    return null;
}

/** Seconds between a clock time at home and the same clock time at the destination on a date. */
function travel_offset(string $ymd, int $hh, int $mm, DateTimeZone $home, DateTimeZone $dest): int
{
    $time = sprintf('%s %02d:%02d', $ymd, $hh, $mm);
    return (new DateTimeImmutable($time, $home))->getTimestamp() - (new DateTimeImmutable($time, $dest))->getTimestamp();
}

/**
 * The moment (UTC) a dose scheduled at $hh:$mm on home-calendar date $ymd is due.
 * @return array{0: DateTimeImmutable, 1: ?array} [moment, the plan that moved it (or null)]
 */
function dose_moment(string $ymd, int $hh, int $mm, DateTimeZone $home, array $plans): array
{
    $homeMoment = new DateTimeImmutable(sprintf('%s %02d:%02d', $ymd, $hh, $mm), $home);
    foreach ($plans as $plan) {
        try {
            $dest = new DateTimeZone($plan['destination_tz']);
        } catch (Exception) {
            continue;
        }
        $offset = travel_offset($ymd, $hh, $mm, $home, $dest);
        $during = $ymd >= $plan['start_date'] && $ymd <= $plan['end_date'];

        if ($during && $plan['mode'] === 'home') {
            return [$homeMoment, $plan];
        }
        if ($during && $plan['mode'] === 'local') {
            return [$homeMoment->modify(sprintf('%+d seconds', -$offset)), $plan];
        }
        if ($plan['mode'] === 'gradual') {
            $step = max(1, (int) $plan['shift_hours']) * 3600;
            if ($during) {
                $day = (int) (new DateTimeImmutable($plan['start_date']))->diff(new DateTimeImmutable($ymd))->days + 1;
                $shift = max(-$step * $day, min($step * $day, $offset));
                return [$homeMoment->modify(sprintf('%+d seconds', -$shift)), $plan];
            }
            // Coming home: step back towards home time the same way.
            if ($ymd > $plan['end_date']) {
                $daysBack = (int) (new DateTimeImmutable($plan['end_date']))->diff(new DateTimeImmutable($ymd))->days;
                $remaining = max(0, abs($offset) - $step * $daysBack);
                if ($remaining > 0) {
                    $shift = $offset > 0 ? $remaining : -$remaining;
                    return [$homeMoment->modify(sprintf('%+d seconds', -$shift)), $plan];
                }
            }
        }
    }
    return [$homeMoment, null];
}

/** "UTC+9" style label for a time zone right now. */
function tz_offset_label(string $tz): string
{
    try {
        $seconds = (new DateTimeImmutable('now', new DateTimeZone($tz)))->getOffset();
    } catch (Exception) {
        return '';
    }
    $sign = $seconds < 0 ? '−' : '+';
    $seconds = abs($seconds);
    return 'UTC' . $sign . intdiv($seconds, 3600) . ($seconds % 3600 ? ':' . sprintf('%02d', intdiv($seconds % 3600, 60)) : '');
}

/** Friendly place name from a time zone id: "Asia/Tokyo" -> "Tokyo". */
function tz_place(string $tz): string
{
    $parts = explode('/', $tz);
    return str_replace('_', ' ', end($parts));
}
