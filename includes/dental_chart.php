<?php
// Interactive dental chart, Universal Numbering (1–32), drawn as inline SVG.

const TOOTH_STATUSES = [
    'healthy'    => ['Healthy', '#22b35e', '#effaf3'],
    'cavity'     => ['Cavity', '#b45309', '#fdf3e7'],
    'filled'     => ['Filled', '#3b76e0', '#eef3fd'],
    'crown'      => ['Crown', '#e0a91a', '#fff8e1'],
    'extracted'  => ['Extracted', '#df4a3e', '#fdeceb'],
    'root_canal' => ['Root Canal', '#a45bd8', '#f6eefc'],
    'braces'     => ['Braces', '#5a5fd6', '#eeeffc'],
    'missing'    => ['Missing', '#a3acb0', '#f2f4f5'],
    'prosthetic' => ['Prosthetic', '#f07b1f', '#fef1e6'],
];

/** Position within an arch half: 0 = third molar … 7 = central incisor. */
function tooth_kind(int $no): string
{
    $i = ($no - 1) % 16;           // 0..15 across the arch
    $fromMid = $i < 8 ? $i : 15 - $i; // 0..7, 7 nearest the midline
    return match (true) {
        $fromMid <= 2 => 'molar',
        $fromMid <= 4 => 'premolar',
        $fromMid === 5 => 'canine',
        default => 'incisor',
    };
}

function tooth_name(int $no): string
{
    $upper = $no <= 16;
    $i = ($no - 1) % 16;
    $right = $upper ? $i < 8 : $i >= 8;  // lower arch runs 17 (left) to 32 (right)
    $fromMid = $i < 8 ? $i : 15 - $i;
    $names = ['third molar', 'second molar', 'first molar', 'second premolar', 'first premolar', 'canine', 'lateral incisor', 'central incisor'];
    return ucfirst(($upper ? 'upper ' : 'lower ') . ($right ? 'right ' : 'left ') . $names[$fromMid]);
}

function tooth_mark(string $status, float $w, float $h): string
{
    $hw = $w / 2;
    $hh = $h / 2;
    return match ($status) {
        'cavity'     => '<circle class="mark" cx="0" cy="0" r="5"/>',
        'filled'     => '<rect class="mark" x="-7" y="-7" width="14" height="14" rx="2"/>',
        'crown'      => sprintf('<rect class="mark" x="%.1f" y="%.1f" width="%.1f" height="8" rx="2"/>', -$hw + 6, -$hh + 6, $w - 12),
        'extracted'  => sprintf('<path class="mark" d="M%1$.1f %2$.1f L%3$.1f %4$.1f M%3$.1f %2$.1f L%1$.1f %4$.1f" stroke-width="3" stroke-linecap="round"/>', -$hw + 6, -$hh + 6, $hw - 6, $hh - 6),
        'root_canal' => sprintf('<path class="mark" d="M0 %.1f V%.1f" stroke-width="3" stroke-linecap="round"/>', -$hh + 8, $hh - 8),
        'braces'     => sprintf('<path class="mark" d="M%.1f 0 H%.1f" stroke-width="2"/><rect class="mark" x="-5" y="-5" width="10" height="10" rx="1"/>', -$hw, $hw),
        'prosthetic' => sprintf('<rect class="mark" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4" fill="none" stroke-width="2" stroke-dasharray="3 3"/>', -$hw + 7, -$hh + 7, $w - 14, $h - 14),
        default      => '',
    };
}

/**
 * @param array<int,string> $state tooth_no => status (missing keys are healthy)
 */
function render_dental_chart(array $state): string
{
    $sizes = ['molar' => [50, 42], 'premolar' => [38, 42], 'canine' => [34, 46], 'incisor' => [32, 46]];
    $labels = array_map(fn($s) => $s[0], TOOTH_STATUSES);
    $svg = '';

    foreach ([true, false] as $upper) {
        for ($i = 0; $i < 16; $i++) {
            // Screen left is the patient's right: upper row shows 1..16, lower row 32..17.
            $no = $upper ? $i + 1 : 32 - $i;
            $x = 90 + $i * (820 / 15);
            $t = ($x - 500) / 410;
            $y = $upper ? 180 - 120 * $t * $t : 290 + 120 * $t * $t;
            $slope = ($upper ? -240 : 240) * $t / 410;
            $angle = rad2deg(atan($slope));
            [$w, $h] = $sizes[tooth_kind($no)];
            $status = $state[$no] ?? 'healthy';

            $svg .= sprintf(
                '<g class="tooth s-%s" data-tooth="%d" data-status="%s" data-name="%s" tabindex="0" role="button" aria-label="Tooth %d, %s: %s">'
                . '<g transform="translate(%.1f %.1f) rotate(%.1f)"><rect class="body" x="%.1f" y="%.1f" width="%d" height="%d" rx="9"/>%s</g>'
                . '<text class="num" x="%.1f" y="%.1f" text-anchor="middle">%d</text></g>',
                $status, $no, $status, e(tooth_name($no)), $no, e(tooth_name($no)), e($labels[$status]),
                $x, $y, $angle, -$w / 2, -$h / 2, $w, $h, tooth_mark($status, $w, $h),
                $x, $y + $h / 2 + 22, $no
            );
        }
    }

    return '<div class="chart-box" data-dental-chart data-labels="' . e(json_encode($labels)) . '">'
        . '<span class="corner" style="left:16px;top:12px">Upper Arch</span>'
        . '<span class="corner" style="right:16px;top:12px">Universal Numbering</span>'
        . '<span class="corner" style="left:16px;bottom:12px">Lower Arch</span>'
        . '<svg viewBox="0 0 1000 470" role="group" aria-label="Dental chart">'
        . '<line x1="500" y1="40" x2="500" y2="440" style="stroke:var(--chart-mid)" stroke-dasharray="6 6"/>'
        . '<text x="40" y="236" style="fill:var(--tooth-num)" font-size="18">R</text><text x="948" y="236" style="fill:var(--tooth-num)" font-size="18">L</text>'
        . $svg . '</svg></div>';
}

function render_chart_legend(): string
{
    $html = '<div class="legend">';
    foreach (TOOTH_STATUSES as [$label, $color]) {
        $html .= '<span><i style="border-color:' . $color . '"></i>' . e($label) . '</span>';
    }
    return $html . '</div>';
}
