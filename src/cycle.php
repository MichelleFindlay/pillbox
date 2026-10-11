<?php
declare(strict_types=1);

/*
 * Cycle tracking (Periods and contraception): logged periods, average cycle and period length,
 * and when the next period is expected. No fertile-window predictions: they're not
 * reliable enough to use as contraception.
 */

const CYCLE_FLOW = ['light' => 'Light', 'medium' => 'Medium', 'heavy' => 'Heavy'];

function cycle_periods(int $userId, int $limit = 12): array
{
    return q('SELECT * FROM cycle_periods WHERE user_id = ? ORDER BY start_date DESC LIMIT ' . max(1, $limit), [$userId])->fetchAll();
}

/**
 * From periods (newest first): average cycle and period length over the last 6 cycles,
 * the shortest and longest cycle, and the expected start of the next period.
 * Gaps over 90 days are left out (probably periods that weren't logged).
 * @return array{cycle: ?float, period: ?float, shortest: ?int, longest: ?int, next: ?DateTimeImmutable, cycles: int}
 */
function cycle_stats(array $periods, DateTimeZone $tz): array
{
    $lengths = [];
    for ($i = 0; $i + 1 < count($periods) && count($lengths) < 6; $i++) {
        $days = (new DateTimeImmutable($periods[$i + 1]['start_date']))->diff(new DateTimeImmutable($periods[$i]['start_date']))->days;
        if ($days >= 10 && $days <= 90) {
            $lengths[] = $days;
        }
    }
    $periodLengths = [];
    foreach (array_slice($periods, 0, 6) as $p) {
        if ($p['end_date']) {
            $periodLengths[] = (new DateTimeImmutable($p['start_date']))->diff(new DateTimeImmutable($p['end_date']))->days + 1;
        }
    }
    $cycle = $lengths ? array_sum($lengths) / count($lengths) : null;
    return [
        'cycle'    => $cycle,
        'period'   => $periodLengths ? array_sum($periodLengths) / count($periodLengths) : null,
        'shortest' => $lengths ? min($lengths) : null,
        'longest'  => $lengths ? max($lengths) : null,
        'next'     => $cycle && $periods ? (new DateTimeImmutable($periods[0]['start_date'], $tz))->modify('+' . (int) round($cycle) . ' days') : null,
        'cycles'   => count($lengths),
    ];
}

/** A short line about the next period, for Today: only when it's due within 3 days or late. */
function cycle_today_note(array $user, DateTimeImmutable $today): ?string
{
    $periods = cycle_periods((int) $user['id'], 7);
    if (!$periods || ($periods[0]['end_date'] === null && $periods[0]['start_date'] >= $today->modify('-10 days')->format('Y-m-d'))) {
        return null; // nothing logged, or a period is happening now
    }
    $next = cycle_stats($periods, $today->getTimezone())['next'];
    if (!$next) {
        return null;
    }
    $days = (int) $today->diff($next)->format('%r%a');
    return match (true) {
        $days < -2 => 'Period ' . -$days . ' days later than expected',
        $days <= 0 => 'Period expected around now',
        $days <= 3 => 'Period expected in about ' . $days . ' day' . ($days === 1 ? '' : 's'),
        default => null,
    };
}
