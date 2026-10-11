<?php
declare(strict_types=1);

/**
 * Server-rendered SVG line chart for readings over time.
 * Colours come from CSS (.series-N) so light/dark mode is handled in style.css.
 *
 * @param array $series list of ['label' => string, 'unit' => string, 'points' => [[unixTs, float], ...]]
 * @param array $bands  optional shaded ranges: ['low' => ?float, 'high' => ?float, 'class' => 'band-target'|'band-lab', 'label' => string]
 * @param array $markers optional events as vertical lines: [[unixTs, label], ...] (e.g. dose changes)
 */
function svg_line_chart(array $series, int $fromTs, int $toTs, string $title, array $bands = [], array $markers = []): string
{
    $series = array_values(array_filter($series, fn ($s) => $s['points']));
    if (!$series) {
        return '<p class="muted">No readings in this period yet.</p>';
    }

    $w = 640;
    $h = 240;
    $padL = 44;
    $padR = 56;
    $padT = 14;
    $padB = 28;

    $all = [];
    foreach ($series as $s) {
        foreach ($s['points'] as [, $v]) {
            $all[] = $v;
        }
    }
    foreach ($bands as $b) {
        foreach (['low', 'high'] as $k) {
            if ($b[$k] !== null) {
                $all[] = (float) $b[$k];
            }
        }
    }
    [$yMin, $yMax, $ticks] = nice_scale(min($all), max($all));
    if ($toTs <= $fromTs) {
        $toTs = $fromTs + 86400;
    }

    $x = fn (int $ts): float => $padL + ($ts - $fromTs) / ($toTs - $fromTs) * ($w - $padL - $padR);
    $y = fn (float $v): float => $padT + ($yMax - $v) / ($yMax - $yMin) * ($h - $padT - $padB);

    $out = '<div class="chart">';
    if (count($series) > 1) {
        $out .= '<ul class="legend">';
        foreach ($series as $i => $s) {
            $out .= '<li><span class="swatch series-' . ($i + 1) . '"></span>' . h($s['label']) . '</li>';
        }
        $out .= '</ul>';
    }
    $out .= sprintf('<svg viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">', $w, $h, h($title));

    // Grid and y-axis labels.
    foreach ($ticks as $t) {
        $ty = round($y($t), 1);
        $out .= sprintf('<line class="grid" x1="%d" x2="%d" y1="%s" y2="%s"/>', $padL, $w - $padR, $ty, $ty);
        $out .= sprintf('<text class="axis" x="%d" y="%s" text-anchor="end" dominant-baseline="middle">%s</text>', $padL - 6, $ty, h(num($t)));
    }
    // Shaded ranges (e.g. a target range and the lab's range), behind the lines.
    foreach ($bands as $b) {
        $top = $y(min($yMax, (float) ($b['high'] ?? $yMax)));
        $bottom = $y(max($yMin, (float) ($b['low'] ?? $yMin)));
        $out .= sprintf('<rect class="band %s" x="%d" y="%s" width="%d" height="%s"><title>%s</title></rect>', h($b['class']), $padL, round($top, 1),
            $w - $padL - $padR, max(0, round($bottom - $top, 1)), h($b['label']));
    }
    // Events (e.g. dose changes), as dashed vertical lines with a short label.
    foreach ($markers as [$ts, $label]) {
        if ($ts < $fromTs || $ts > $toTs) {
            continue;
        }
        $mx = round($x($ts), 1);
        $out .= sprintf('<g class="marker"><line x1="%s" x2="%s" y1="%d" y2="%d"/><text class="axis" x="%s" y="%d" text-anchor="middle">%s</text><title>%s</title></g>',
            $mx, $mx, $padT, $h - $padB, $mx, $padT - 3 + 10, h(mb_strimwidth($label, 0, 18, '…')), h($label));
    }
    // X-axis date labels: start, middle, end.
    foreach ([$fromTs, intdiv($fromTs + $toTs, 2), $toTs] as $i => $ts) {
        $anchor = ['start', 'middle', 'end'][$i];
        $out .= sprintf('<text class="axis" x="%s" y="%d" text-anchor="%s">%s</text>', round($x($ts), 1), $h - 8, $anchor,
            h((new DateTimeImmutable('@' . $ts))->setTimezone(user_tz())->format('j M')));
    }

    foreach ($series as $i => $s) {
        $cls = 'series-' . ($i + 1);
        $pts = $s['points'];
        usort($pts, fn ($a, $b) => $a[0] <=> $b[0]);
        $path = '';
        foreach ($pts as $j => [$ts, $v]) {
            $path .= ($j ? 'L' : 'M') . round($x($ts), 1) . ' ' . round($y($v), 1);
        }
        $out .= '<path class="line ' . $cls . '" d="' . $path . '"/>';
        foreach ($pts as [$ts, $v]) {
            $cx = round($x($ts), 1);
            $cy = round($y($v), 1);
            $tip = h($s['label'] . ': ' . num($v) . ' ' . $s['unit'] . ' — ' . (new DateTimeImmutable('@' . $ts))->setTimezone(user_tz())->format('D j M, H:i'));
            $out .= sprintf('<g class="pt"><circle class="pt-dot %s" cx="%s" cy="%s" r="4"/><circle class="hit" cx="%s" cy="%s" r="11"><title>%s</title></circle></g>',
                $cls, $cx, $cy, $cx, $cy, $tip);
        }
        // Direct label on the most recent value.
        [$lastTs, $lastV] = end($pts);
        $out .= sprintf('<text class="endlabel" x="%s" y="%s" dominant-baseline="middle">%s</text>',
            round($x($lastTs) + 8, 1), round($y($lastV), 1), h(num($lastV)));
    }

    return $out . '</svg></div>';
}

/** Rounded axis bounds and ~4-5 tick values covering [min, max]. */
function nice_scale(float $min, float $max): array
{
    if ($min === $max) {
        $min -= 1;
        $max += 1;
    }
    $range = $max - $min;
    $rough = $range / 4;
    $mag = 10 ** floor(log10($rough));
    $step = $mag;
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($rough <= $m * $mag) {
            $step = $m * $mag;
            break;
        }
    }
    $lo = floor($min / $step) * $step;
    $hi = ceil($max / $step) * $step;
    $ticks = [];
    for ($t = $lo; $t <= $hi + $step / 2; $t += $step) {
        $ticks[] = round($t, 4);
    }
    return [$lo, $hi, $ticks];
}
