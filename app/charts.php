<?php
/**
 * Charts for the Dashboard, drawn on the server as inline SVG.
 *
 * The Content-Security-Policy allows no inline script and no style attributes, so a chart here is
 * geometry in SVG attributes and colour from classes in style.css. app.js adds the hover tooltip;
 * without it each target still carries a native <title>, and every figure is also printed in a
 * table or a legend beside its chart.
 *
 * Nothing in this file touches the database. The scale and label helpers are covered by tests/run.php.
 */
declare(strict_types=1);

/** Gridline intervals above the baseline on every plotted chart. */
const CHART_INTERVALS = 4;

/** Drawing area, in SVG user units. At the desktop width of its panel one unit is one pixel. */
const CHART_WIDTH      = 700;    // a two-thirds panel
const CHART_WIDTH_FULL = 1084;   // a full-width panel
const CHART_HEIGHT     = 268;
const CHART_MARGIN = ['top' => 10, 'right' => 6, 'bottom' => 40, 'left' => 58];

const CHART_COLUMN_MAX = 24;   // a column never fills its slot; the rest of the band is air
const CHART_COLUMN_GAP = 2;    // surface gap between touching marks
const CHART_COLUMN_RADIUS = 4; // rounded data end; the baseline end stays square

// ---------------------------------------------------------------------------
// Scales and labels (pure)
// ---------------------------------------------------------------------------

/**
 * The distance between gridlines: the smallest of 1, 2, 5, 10, 20, 25, 50, 100, … that puts $max
 * on or below the top gridline. Always a whole number, so a count axis never shows "2.5 bookings".
 */
function axis_step(int $max, int $intervals = CHART_INTERVALS): int
{
    $max = max(0, $max);
    for ($pow = 1; ; $pow *= 10) {
        $steps = $pow >= 10 ? [$pow, 2 * $pow, intdiv(5 * $pow, 2), 5 * $pow] : [$pow, 2 * $pow, 5 * $pow];
        foreach ($steps as $step) {
            if ($step * $intervals >= $max) {
                return $step;
            }
        }
    }
}

/** Gridline values from the baseline to the top: [0, step, 2·step, …]. */
function axis_ticks(int $max, int $intervals = CHART_INTERVALS): array
{
    $step = axis_step($max, $intervals);
    $ticks = [];
    for ($i = 0; $i <= $intervals; $i++) {
        $ticks[] = $i * $step;
    }
    return $ticks;
}

/**
 * paisa → a short figure for a tile or an axis: "Rs. 950", "Rs. 45.5K", "Rs. 12.3 Lakh", "Rs. 1.2 Cr".
 * One decimal, dropped when it is zero. The exact amount is always shown beside it with format_rs().
 */
function compact_rs(int $paisa): string
{
    $sign = $paisa < 0 ? '-' : '';
    $rupees = intdiv(abs($paisa), 100);
    $units = [[10000000, ' Cr'], [100000, ' Lakh'], [1000, 'K']];
    foreach ($units as $i => [$size, $suffix]) {
        if ($rupees < $size) {
            continue;
        }
        $tenths = intdiv($rupees * 10 + intdiv($size, 2), $size);   // rounded to one decimal
        if ($i > 0 && $tenths >= 1000) {
            // 99,96,000 rounds to "100 Lakh": say "1 Cr" instead.
            [$size, $suffix] = $units[$i - 1];
            $tenths = 10;
        }
        $whole = group_digits_south_asian((string) intdiv($tenths, 10));
        return 'Rs. ' . $sign . $whole . ($tenths % 10 ? '.' . ($tenths % 10) : '') . $suffix;
    }
    return 'Rs. ' . $sign . $rupees;
}

/**
 * Change from $before to $now as a whole percentage, or null when there is nothing to compare
 * against (a previous period of zero has no meaningful percentage).
 */
function percent_change(int $now, int $before): ?int
{
    return $before <= 0 ? null : (int) round(($now - $before) * 100 / $before);
}

/** $part as a whole percentage of $whole, kept between 0 and 100. Zero when there is no whole. */
function percent_of(int $part, int $whole): int
{
    return $whole <= 0 ? 0 : max(0, min(100, (int) round($part * 100 / $whole)));
}

/** True when there is nothing to plot: every value of every series is zero. */
function chart_is_empty(array $series): bool
{
    foreach ($series as $s) {
        foreach ($s['values'] as $value) {
            if ($value !== 0) {
                return false;
            }
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// SVG pieces shared by the plotted charts
// ---------------------------------------------------------------------------

/** A coordinate for an SVG attribute: at most one decimal, never locale-formatted. */
function svg_n(float $value): string
{
    $s = number_format($value, 1, '.', '');
    return substr($s, -2) === '.0' ? substr($s, 0, -2) : $s;
}

/**
 * The plot's frame for a run of periods: where the baseline is, how wide a period's band is, the
 * gridline values and the value → height scale.
 */
function chart_frame(int $periodCount, int $peak, int $width): array
{
    $m = CHART_MARGIN;
    $plotW = $width - $m['left'] - $m['right'];
    $plotH = CHART_HEIGHT - $m['top'] - $m['bottom'];
    $ticks = axis_ticks($peak);
    $axisMax = max(1, end($ticks));
    return [
        'width'    => $width,
        'left'     => $m['left'],
        'right'    => $width - $m['right'],
        'top'      => $m['top'],
        'plotH'    => $plotH,
        'baseline' => $m['top'] + $plotH,
        'band'     => $plotW / max(1, $periodCount),
        'ticks'    => $ticks,
        'scale'    => static fn(int $value): float => max(0, $value) / $axisMax * $plotH,
    ];
}

/** The opening tag, the gridlines and their labels. The baseline is a shade stronger than the grid. */
function svg_chart_open(array $f, callable $tickLabel, string $ariaLabel, string $class = 'chart'): string
{
    $svg = '<svg class="' . h($class) . '" viewBox="0 0 ' . $f['width'] . ' ' . CHART_HEIGHT . '" role="group" aria-label="' . h($ariaLabel) . '">';
    foreach ($f['ticks'] as $tick) {
        $y = $f['baseline'] - $f['scale']($tick);
        $svg .= '<line class="' . ($tick === 0 ? 'chart-axis' : 'chart-grid') . '" x1="' . $f['left'] . '" x2="' . $f['right']
            . '" y1="' . svg_n($y) . '" y2="' . svg_n($y) . '"/>'
            . '<text class="chart-tick" x="' . ($f['left'] - 8) . '" y="' . svg_n($y + 4) . '" text-anchor="end">' . h($tickLabel($tick)) . '</text>';
    }
    return $svg;
}

/** A period's label under the baseline: the month, and the year under it where one is given. */
function svg_chart_label(array $f, int $i, array $period): string
{
    $centre = $f['left'] + ($i + 0.5) * $f['band'];
    $svg = '<text class="chart-xlabel" x="' . svg_n($centre) . '" y="' . ($f['baseline'] + 17) . '" text-anchor="middle">' . h($period['label']) . '</text>';
    if ($period['sub'] !== '') {
        $svg .= '<text class="chart-xlabel chart-xsub" x="' . svg_n($centre) . '" y="' . ($f['baseline'] + 31) . '" text-anchor="middle">' . h($period['sub']) . '</text>';
    }
    return $svg;
}

/**
 * One hover/focus target per period, the full height of the plot: the reader aims at a month, not
 * at a bar or a dot. $rows are [series class, name, displayed value].
 */
function svg_chart_hit(array $f, int $i, array $period, array $rows): string
{
    $plain = $period['title'] . ' — ' . implode('; ', array_map(static fn(array $r) => $r[1] . ' ' . $r[2], $rows));
    return '<rect class="chart-hit" tabindex="0" x="' . svg_n($f['left'] + $i * $f['band']) . '" y="' . $f['top'] . '" width="' . svg_n($f['band'])
        . '" height="' . $f['plotH'] . '" aria-label="' . h($plain) . '" data-tip="'
        . h(json_encode(['title' => $period['title'], 'rows' => $rows], JSON_UNESCAPED_UNICODE)) . '">'
        . '<title>' . h($plain) . '</title></rect>';
}

/** A column with a rounded top and a square foot, standing on $baseline. Empty when it has no height. */
function svg_column(float $x, float $baseline, float $width, float $height, string $class, bool $roundTop): string
{
    if ($height <= 0) {
        return '';
    }
    $top = $baseline - $height;
    $r = $roundTop ? min(CHART_COLUMN_RADIUS, $height, $width / 2) : 0;
    if ($r <= 0) {
        return '<rect class="' . h($class) . '" x="' . svg_n($x) . '" y="' . svg_n($top) . '" width="' . svg_n($width)
            . '" height="' . svg_n($height) . '"/>';
    }
    $right = $x + $width;
    return '<path class="' . h($class) . '" d="M' . svg_n($x) . ',' . svg_n($baseline)
        . 'V' . svg_n($top + $r) . 'Q' . svg_n($x) . ',' . svg_n($top) . ' ' . svg_n($x + $r) . ',' . svg_n($top)
        . 'H' . svg_n($right - $r) . 'Q' . svg_n($right) . ',' . svg_n($top) . ' ' . svg_n($right) . ',' . svg_n($top + $r)
        . 'V' . svg_n($baseline) . 'Z"/>';
}

// ---------------------------------------------------------------------------
// Column chart
// ---------------------------------------------------------------------------

/**
 * A column chart over a row of periods: series side by side, or stacked into one column.
 *
 * Negative values (a month where refunds outweighed payments) are drawn as no column — the true
 * figure is still in the tooltip and the table. All series share the one axis.
 *
 * @param array    $periods   one per column group: ['label' => 'Jan', 'sub' => '2026' or '', 'title' => 'January 2026']
 * @param array    $series    each ['class' => CSS class, 'name' => string, 'values' => int[], 'display' => string[]],
 *                            values and display indexed like $periods; stacked series are listed bottom first
 * @param callable $tickLabel fn(int $value): string, for the gridline labels
 * @param ?array   $totals    stacked charts: the column total as displayed, one per period (shown in the tooltip)
 */
function svg_column_chart(array $periods, array $series, callable $tickLabel, bool $stacked, string $ariaLabel,
                          ?array $totals = null, int $width = CHART_WIDTH): string
{
    // The tallest thing on the chart: one column, or one whole stack.
    $peak = 0;
    foreach (array_keys($periods) as $i) {
        $sum = 0;
        foreach ($series as $s) {
            $value = max(0, (int) $s['values'][$i]);
            $sum += $value;
            $peak = max($peak, $stacked ? $sum : $value);
        }
    }
    $f = chart_frame(count($periods), $peak, $width);
    $band = $f['band'];
    $baseline = $f['baseline'];
    $scale = $f['scale'];

    $seriesCount = max(1, count($series));
    if ($stacked) {
        $colW = min(CHART_COLUMN_MAX, max(6, floor($band * 0.5)));
        $groupW = $colW;
    } else {
        $colW = min(CHART_COLUMN_MAX, max(4, floor(($band * 0.64 - CHART_COLUMN_GAP * ($seriesCount - 1)) / $seriesCount)));
        $groupW = $seriesCount * $colW + ($seriesCount - 1) * CHART_COLUMN_GAP;
    }

    $marks = '';
    $hits = '';
    foreach ($periods as $i => $period) {
        $x = $f['left'] + $i * $band + ($band - $groupW) / 2;

        if ($stacked) {
            // Which segment is on top decides where the rounded end goes.
            $topIndex = null;
            foreach ($series as $k => $s) {
                if ((int) $s['values'][$i] > 0) {
                    $topIndex = $k;
                }
            }
            $floor = $baseline;
            $first = true;
            foreach ($series as $k => $s) {
                $height = $scale((int) $s['values'][$i]);
                if ($height > 0) {
                    // The gap is shaved off the foot of every segment but the lowest.
                    $gap = $first ? 0 : min(CHART_COLUMN_GAP, max(0, $height - 1));
                    $marks .= svg_column($x, $floor - $gap, $colW, $height - $gap, 'chart-mark ' . $s['class'], $k === $topIndex);
                    $floor -= $height;
                    $first = false;
                }
            }
        } else {
            foreach ($series as $k => $s) {
                $marks .= svg_column($x + $k * ($colW + CHART_COLUMN_GAP), $baseline, $colW, $scale((int) $s['values'][$i]),
                    'chart-mark ' . $s['class'], true);
            }
        }

        // The readout lists a stack top-down, the way it is drawn.
        $rows = [];
        foreach ($stacked ? array_reverse($series) : $series as $s) {
            $rows[] = [$s['class'], $s['name'], $s['display'][$i]];
        }
        if ($totals !== null) {
            $rows[] = ['', 'Total', $totals[$i]];
        }
        $marks .= svg_chart_label($f, $i, $period);
        $hits .= svg_chart_hit($f, $i, $period, $rows);
    }

    return svg_chart_open($f, $tickLabel, $ariaLabel) . $marks . $hits . '</svg>';
}

// ---------------------------------------------------------------------------
// Area chart
// ---------------------------------------------------------------------------

/**
 * A line per series over a row of periods, each with a light wash beneath it and a dot on every
 * period. The curve eases between points without ever rising above the higher one or dipping below
 * the lower, so it cannot suggest a value that was never there.
 *
 * Parameters as svg_column_chart(). Negative values sit on the baseline; the tooltip has the figure.
 */
function svg_area_chart(array $periods, array $series, callable $tickLabel, string $ariaLabel, int $width = CHART_WIDTH_FULL): string
{
    $peak = 0;
    foreach ($series as $s) {
        foreach ($s['values'] as $value) {
            $peak = max($peak, (int) $value);
        }
    }
    $f = chart_frame(count($periods), $peak, $width);
    $baseline = $f['baseline'];

    $areas = '';
    $lines = '';
    $dots = '';
    foreach ($series as $s) {
        $points = [];
        foreach (array_keys($periods) as $i) {
            $points[] = [$f['left'] + ($i + 0.5) * $f['band'], $baseline - $f['scale']((int) $s['values'][$i])];
        }
        if (!$points) {
            continue;
        }
        $d = 'M' . svg_n($points[0][0]) . ',' . svg_n($points[0][1]);
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $mid = ($points[$i - 1][0] + $points[$i][0]) / 2;
            $d .= 'C' . svg_n($mid) . ',' . svg_n($points[$i - 1][1]) . ' ' . svg_n($mid) . ',' . svg_n($points[$i][1])
                . ' ' . svg_n($points[$i][0]) . ',' . svg_n($points[$i][1]);
        }
        $last = $points[count($points) - 1];
        $areas .= '<path class="chart-area ' . h($s['class']) . '" d="' . $d . 'L' . svg_n($last[0]) . ',' . svg_n($baseline)
            . 'L' . svg_n($points[0][0]) . ',' . svg_n($baseline) . 'Z"/>';
        $lines .= '<path class="chart-line ' . h($s['class']) . '" d="' . $d . '"/>';
        foreach ($points as [$x, $y]) {
            $dots .= '<circle class="chart-dot ' . h($s['class']) . '" cx="' . svg_n($x) . '" cy="' . svg_n($y) . '" r="4"/>';
        }
    }

    $labels = '';
    $hits = '';
    foreach ($periods as $i => $period) {
        $rows = [];
        foreach ($series as $s) {
            $rows[] = [$s['class'], $s['name'], $s['display'][$i]];
        }
        $labels .= svg_chart_label($f, $i, $period);
        $hits .= svg_chart_hit($f, $i, $period, $rows);
    }

    return svg_chart_open($f, $tickLabel, $ariaLabel, 'chart chart-wide') . $areas . $lines . $dots . $labels . $hits . '</svg>';
}

// ---------------------------------------------------------------------------
// Donut and gauge
// ---------------------------------------------------------------------------

/**
 * A ring split into shares. Each segment is a stroked circle whose dash is the share's arc, with a
 * small surface gap after it. The figures themselves belong in the legend beside the ring; the
 * centre carries the one headline.
 *
 * @param array $segments each ['class' => CSS class, 'name' => string, 'value' => int, 'display' => string];
 *                        values of zero or less take no arc
 */
function svg_donut(array $segments, string $centre, string $centreLabel, string $ariaLabel): string
{
    $size = 180;
    $r = 68;
    $c = $size / 2;
    $circumference = 2 * M_PI * $r;
    $total = 0;
    foreach ($segments as $s) {
        $total += max(0, (int) $s['value']);
    }

    $svg = '<svg class="donut" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . h($ariaLabel) . '">'
        . '<circle class="donut-track" cx="' . $c . '" cy="' . $c . '" r="' . $r . '"/>';
    $start = 0.0;
    foreach ($segments as $s) {
        $value = max(0, (int) $s['value']);
        if ($value === 0 || $total === 0) {
            continue;
        }
        $arc = $value / $total * $circumference;
        $dash = $value === $total ? $arc : max(1, $arc - 2);   // the gap: surface showing between neighbours
        $svg .= '<circle class="donut-seg ' . h($s['class']) . '" cx="' . $c . '" cy="' . $c . '" r="' . $r . '" stroke-dasharray="'
            . svg_n($dash) . ' ' . svg_n($circumference - $dash) . '" stroke-dashoffset="' . svg_n(-$start) . '" transform="rotate(-90 ' . $c . ' ' . $c . ')">'
            . '<title>' . h($s['name'] . ' — ' . $s['display']) . '</title></circle>';
        $start += $arc;
    }
    return $svg . '<text class="donut-centre" x="' . $c . '" y="' . ($c + 2) . '" text-anchor="middle">' . h($centre) . '</text>'
        . '<text class="donut-label" x="' . $c . '" y="' . ($c + 20) . '" text-anchor="middle">' . h($centreLabel) . '</text></svg>';
}

/**
 * A half-ring meter: $pct of the arc filled, left to right over the top, on a lighter track of the
 * same colour. The percentage is printed under the arch.
 */
function svg_gauge(int $pct, string $class, string $ariaLabel): string
{
    $pct = max(0, min(100, $pct));
    $r = 80;
    $cx = 100;
    $cy = 100;
    $half = M_PI * $r;
    $rest = 2 * M_PI * $r;   // a gap longer than the half, so nothing is drawn below the diameter
    $arc = static fn(string $cls, float $length): string => '<circle class="' . $cls . '" cx="' . $cx . '" cy="' . $cy . '" r="' . $r
        . '" stroke-dasharray="' . svg_n($length) . ' ' . svg_n($rest) . '" transform="rotate(180 ' . $cx . ' ' . $cy . ')"/>';
    return '<svg class="gauge" viewBox="0 0 200 112" role="img" aria-label="' . h($ariaLabel) . '">'
        . $arc('gauge-track ' . h($class), $half)
        . ($pct > 0 ? $arc('gauge-fill ' . h($class), $half * $pct / 100) : '')
        . '<text class="gauge-value" x="' . $cx . '" y="' . ($cy - 4) . '" text-anchor="middle">' . $pct . '%</text></svg>';
}

/**
 * The bar in a ranked table row: $value as a share of $max. The width is a percentage attribute, so
 * the bar follows its cell without stretching the rounded end. The figure itself sits in the next
 * cell, so the bar is decoration to a screen reader.
 */
function svg_rank_bar(int $value, int $max, string $class): string
{
    $pct = $max > 0 ? (int) round(max(0, $value) / $max * 100) : 0;
    $pct = $value > 0 ? max(1, min(100, $pct)) : 0;
    $svg = '<svg class="rank-bar" width="100%" height="8" aria-hidden="true" focusable="false">';
    if ($pct > 0) {
        $svg .= '<rect class="' . h($class) . '" width="' . $pct . '%" height="8" rx="4"/>';
        if ($pct >= 6) {
            $svg .= '<rect class="' . h($class) . '" width="4" height="8"/>';   // squares off the baseline end
        }
    }
    return $svg . '</svg>';
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

/** Line icons for the dashboard tiles, drawn on a 24-unit grid and coloured by the text around them. */
const DASH_ICONS = [
    'money'    => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5M18 9.5v5"/>',
    'wallet'   => '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h11.5v3"/><rect x="3.5" y="8" width="17" height="11" rx="2"/><path d="M16 13.5h1.5"/>',
    'alert'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.2v.3"/>',
    'percent'  => '<path d="M18.5 5.5l-13 13"/><circle cx="7.2" cy="7.2" r="2.4"/><circle cx="16.8" cy="16.8" r="2.4"/>',
    'list'     => '<rect x="4.5" y="3.5" width="15" height="17" rx="2"/><path d="M8.5 8.5h7M8.5 12h7M8.5 15.5h4"/>',
    'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.3 2"/>',
    'today'    => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4M9 15l2 2 4-4"/>',
    'draft'    => '<path d="M6 3.5h8l4.5 4.5v12.5h-12.5z"/><path d="M14 3.5V8h4.5M9 13h6M9 16.5h4"/>',
    'cancel'   => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
    'users'    => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5c.6-3.4 3-5.2 6-5.2s5.4 1.8 6 5.2M16 5.6a3.2 3.2 0 0 1 0 5.8M18.2 14.8c1.6 .9 2.5 2.5 2.8 4.7"/>',
    'venue'    => '<path d="M4 20.5h16M6 20.5V9.5l6-5 6 5v11"/><path d="M10 20.5V15h4v5.5"/>',
];

function dash_icon(string $name): string
{
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . (DASH_ICONS[$name] ?? '') . '</svg>';
}
