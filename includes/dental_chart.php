<?php
// Dental Record Chart, following the clinic's paper form: FDI numbering for permanent (11–48) and
// temporary (51–85) teeth, two status boxes per tooth (condition; restoration or surgery), and
// five surfaces per tooth drawn as the form's ring diagram. Rendered as inline SVG.

/** Legend: Condition (first status box). Values: [label, colour]. */
const TOOTH_CONDITIONS = [
    '/'  => ['Present teeth', '#2f8f6b'],
    'D'  => ['Decayed (caries indicated for filling)', '#d0453a'],
    'M'  => ['Missing due to caries', '#7c8a8f'],
    'MO' => ['Missing due to other causes', '#7c8a8f'],
    'Im' => ['Impacted tooth', '#b26a00'],
    'Sp' => ['Supernumerary tooth', '#8a55c4'],
    'Rf' => ['Root fragment', '#b26a00'],
    'Un' => ['Unerupted', '#7c8a8f'],
];

/** Legend: Restorations & Prosthetics, then Surgery (second status box). */
const TOOTH_RESTORATIONS = [
    'Am'  => ['Amalgam filling', '#3b6fd6'],
    'Co'  => ['Composite filling', '#3b6fd6'],
    'JC'  => ['Jacket crown', '#c9961a'],
    'In'  => ['Inlay', '#3b6fd6'],
    'Imp' => ['Implant', '#c9961a'],
    'S'   => ['Sealant', '#2a9db0'],
    'Rm'  => ['Removable denture', '#c9961a'],
    'Ab'  => ['Abutment', '#c9961a'],
];
const TOOTH_SURGERY = [
    'X'  => ['Extraction due to caries', '#d0453a'],
    'XO' => ['Extraction due to other causes', '#d0453a'],
];

/** Tooth surfaces, as marked on the ring diagram. */
const TOOTH_SURFACES = [
    'M' => 'Mesial', 'D' => 'Distal', 'B' => 'Buccal / Labial', 'L' => 'Lingual / Palatal', 'O' => 'Occlusal / Incisal',
];

/** The examination sections printed under the chart. */
const PERIODONTAL_OPTIONS = ['gingivitis' => 'Gingivitis', 'early' => 'Early Periodontitis', 'moderate' => 'Moderate Periodontitis', 'advanced' => 'Advanced Periodontitis'];
const APPLIANCE_OPTIONS = ['orthodontic' => 'Orthodontic', 'stayplate' => 'Stayplate'];
const TMD_OPTIONS = ['clenching' => 'Clenching', 'clicking' => 'Clicking', 'trismus' => 'Trismus', 'muscle_spasm' => 'Muscle spasm'];
const XRAY_OPTIONS = ['periapical' => 'Periapical', 'panoramic' => 'Panoramic', 'cephalometric' => 'Cephalometric', 'occlusal' => 'Occlusal'];
const OCCLUSION_FIELDS = [
    'molar_class' => 'Classification of Molar', 'canine_class' => 'Classification of Canine', 'overjet' => 'Overjet',
    'overbite' => 'Overbite', 'midline_deviation' => 'Midline Deviation', 'crossbite' => 'Crossbite',
];
const OCCLUSION_CLASSES = ['Class I', 'Class II', 'Class III'];

/** Every tooth on the chart, in FDI numbering. */
function fdi_teeth(): array
{
    static $teeth = null;
    if ($teeth === null) {
        $teeth = [];
        foreach ([1, 2, 3, 4] as $q) for ($p = 1; $p <= 8; $p++) $teeth[] = $q * 10 + $p;
        foreach ([5, 6, 7, 8] as $q) for ($p = 1; $p <= 5; $p++) $teeth[] = $q * 10 + $p;
    }
    return $teeth;
}

function valid_tooth(int $no): bool
{
    return in_array($no, fdi_teeth(), true);
}

function tooth_is_temporary(int $no): bool
{
    return intdiv($no, 10) >= 5;
}

function tooth_name(int $no): string
{
    $q = intdiv($no, 10);
    $p = $no % 10;
    $upper = in_array($q, [1, 2, 5, 6], true);
    $right = in_array($q, [1, 4, 5, 8], true);
    $names = $q >= 5
        ? [1 => 'central incisor', 'lateral incisor', 'canine', 'first molar', 'second molar']
        : [1 => 'central incisor', 'lateral incisor', 'canine', 'first premolar', 'second premolar', 'first molar', 'second molar', 'third molar'];
    return ucfirst(($q >= 5 ? 'temporary ' : '') . ($upper ? 'upper ' : 'lower ') . ($right ? 'right ' : 'left ') . ($names[$p] ?? 'tooth'));
}

/** Label for any chart code (condition, restoration or surgery). */
function tooth_code_label(?string $code): string
{
    $all = TOOTH_CONDITIONS + TOOTH_RESTORATIONS + TOOTH_SURGERY;
    return $code !== null && isset($all[$code]) ? $all[$code][0] : '';
}

function tooth_code_color(?string $code): string
{
    $all = TOOTH_CONDITIONS + TOOTH_RESTORATIONS + TOOTH_SURGERY;
    return $code !== null && isset($all[$code]) ? $all[$code][1] : '#7c8a8f';
}

/** Surfaces as a clean, ordered list from "M,O" style input. */
function clean_surfaces($value): array
{
    $picked = is_array($value) ? $value : explode(',', (string) $value);
    return array_values(array_intersect(array_keys(TOOTH_SURFACES), $picked));
}

/**
 * Converts records made with the earlier chart (Universal numbering 1–32, one status) to this one,
 * once, and creates the examination table. Treatments' tooth numbers are converted too.
 */
function ensure_dental_chart_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    q('CREATE TABLE IF NOT EXISTS dental_exams (
        patient_id        INT UNSIGNED PRIMARY KEY,
        periodontal       VARCHAR(100) NULL,
        molar_class       VARCHAR(40) NULL,
        canine_class      VARCHAR(40) NULL,
        overjet           VARCHAR(40) NULL,
        overbite          VARCHAR(40) NULL,
        midline_deviation VARCHAR(40) NULL,
        crossbite         VARCHAR(40) NULL,
        appliances        VARCHAR(100) NULL,
        appliance_other   VARCHAR(120) NULL,
        tmd               VARCHAR(100) NULL,
        xrays             VARCHAR(100) NULL,
        xray_periapical_teeth VARCHAR(60) NULL,
        xray_occlusal_arch VARCHAR(20) NULL,
        xray_other        VARCHAR(120) NULL,
        updated_by        INT UNSIGNED NULL,
        updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB');
    if (q_val("SHOW COLUMNS FROM tooth_records LIKE 'treatment'")) {
        return;
    }
    // Universal → FDI: 1–8 → 18–11, 9–16 → 21–28, 17–24 → 38–31, 25–32 → 41–48.
    $fdi = 'CASE WHEN tooth_no BETWEEN 1 AND 8 THEN 19 - tooth_no WHEN tooth_no BETWEEN 9 AND 16 THEN tooth_no + 12
        WHEN tooth_no BETWEEN 17 AND 24 THEN 55 - tooth_no WHEN tooth_no BETWEEN 25 AND 32 THEN tooth_no + 16 ELSE tooth_no END';
    q('ALTER TABLE tooth_records MODIFY status VARCHAR(10) NULL');
    q('ALTER TABLE tooth_records ADD treatment VARCHAR(10) NULL AFTER status, ADD surfaces VARCHAR(20) NULL AFTER treatment');
    in_transaction(function () use ($fdi) {
        q("UPDATE tooth_records SET tooth_no = $fdi");
        q("UPDATE treatments SET tooth_no = $fdi WHERE tooth_no IS NOT NULL");
        // Old statuses → paper codes. Statuses with no code keep their meaning in the notes.
        foreach ([
            ['healthy', '/', null, null], ['cavity', 'D', null, null], ['filled', '/', 'Co', null], ['crown', '/', 'JC', null],
            ['extracted', 'M', 'X', null], ['missing', 'MO', null, null], ['prosthetic', '/', 'Rm', null],
            ['root_canal', '/', null, 'Root canal'], ['braces', '/', null, 'Braces'],
        ] as [$old, $status, $treatment, $note]) {
            q("UPDATE tooth_records SET status = ?, treatment = ?,
                notes = CASE WHEN ? IS NULL THEN notes ELSE TRIM(CONCAT(?, IF(notes IS NULL OR notes = '', '', CONCAT(' · ', notes)))) END
                WHERE status = ?", [$status, $treatment, $note, $note, $old]);
        }
    });
}

/**
 * The tooth's ring diagram: four outer surfaces and the centre, coloured where marked.
 * On screen the patient's right is on the left, so mesial (towards the midline) flips per side.
 */
function tooth_ring(int $no, float $cx, float $cy, array $marked, string $fill): string
{
    $q = intdiv($no, 10);
    $upper = in_array($q, [1, 2, 5, 6], true);
    $screenLeft = in_array($q, [1, 4, 5, 8], true);
    $sides = [
        'top' => $upper ? 'B' : 'L', 'bottom' => $upper ? 'L' : 'B',
        'left' => $screenLeft ? 'D' : 'M', 'right' => $screenLeft ? 'M' : 'D',
    ];
    $R = 17;
    $r = 7.5;
    $pt = fn(float $deg, float $rad) => sprintf('%.2f %.2f', $cx + $rad * cos(deg2rad($deg)), $cy + $rad * sin(deg2rad($deg)));
    $segment = function (float $a1, float $a2) use ($pt, $R, $r) {
        return 'M' . $pt($a1, $R) . ' A' . "$R $R 0 0 1 " . $pt($a2, $R) . ' L' . $pt($a2, $r) . ' A' . "$r $r 0 0 0 " . $pt($a1, $r) . 'Z';
    };
    $out = '';
    foreach (['top' => [225, 315], 'right' => [315, 405], 'bottom' => [45, 135], 'left' => [135, 225]] as $side => [$a1, $a2]) {
        $s = $sides[$side];
        $out .= '<path class="surf' . (in_array($s, $marked, true) ? ' on' : '') . '" data-surface="' . $s . '" d="' . $segment($a1, $a2) . '"'
            . (in_array($s, $marked, true) ? ' style="fill:' . $fill . '"' : '') . '/>';
    }
    $out .= sprintf('<circle class="surf%s" data-surface="O" cx="%.2f" cy="%.2f" r="%.1f"%s/>', in_array('O', $marked, true) ? ' on' : '',
        $cx, $cy, $r, in_array('O', $marked, true) ? ' style="fill:' . $fill . '"' : '');
    return $out;
}

/**
 * @param array<int,array{status:?string,treatment:?string,surfaces:?string}> $state current entry per tooth
 */
function render_dental_chart(array $state): string
{
    $cell = 52;
    $mid = 500;
    $colX = function (int $no) use ($cell, $mid): float {
        $q = intdiv($no, 10);
        $p = $no % 10;
        $offset = ($p - 0.5) * $cell + 10;           // distance of the tooth centre from the midline
        return in_array($q, [1, 4, 5, 8], true) ? $mid - $offset : $mid + $offset;
    };
    // [quadrants, ring centre y, top of the two status boxes, number baseline]: upper rows have their
    // boxes above the teeth, lower rows below, as on the paper chart.
    $rows = [
        [[5, 6], 100, 20, 78],
        [[1, 2], 212, 132, 190],
        [[4, 3], 288, 330, 322],
        [[8, 7], 410, 452, 444],
    ];
    $svg = '';
    foreach ($rows as [$quads, $ty, $by, $ny]) {
        foreach ($quads as $q) {
            $count = $q >= 5 ? 5 : 8;
            for ($p = 1; $p <= $count; $p++) {
                $no = $q * 10 + $p;
                $x = $colX($no);
                $entry = $state[$no] ?? ['status' => null, 'treatment' => null, 'surfaces' => null];
                $status = $entry['status'] ?: '';
                $treatment = $entry['treatment'] ?: '';
                $surfaces = clean_surfaces($entry['surfaces']);
                $fill = $treatment && isset(TOOTH_RESTORATIONS[$treatment]) ? tooth_code_color($treatment)
                    : ($status === 'D' ? tooth_code_color('D') : tooth_code_color($treatment ?: $status));
                $gone = in_array($status, ['M', 'MO'], true) || in_array($treatment, ['X', 'XO'], true);
                $classes = 'tooth' . ($status || $treatment ? ' charted' : '') . ($gone ? ' gone' : '')
                    . ($treatment === 'JC' ? ' crowned' : '') . (in_array($status, ['Un', 'Im'], true) ? ' hidden-tooth' : '');
                $summary = trim(implode(', ', array_filter([tooth_code_label($status), tooth_code_label($treatment),
                    $surfaces ? 'surfaces ' . implode('', $surfaces) : ''])), ', ') ?: 'not charted';

                $box = function (string $code, float $y) use ($x, $cell) {
                    return sprintf('<rect class="box" x="%.1f" y="%.1f" width="%d" height="20" rx="3"/>', $x - $cell / 2 + 3, $y, $cell - 6)
                        . ($code !== '' ? sprintf('<text class="code" x="%.1f" y="%.1f" text-anchor="middle" style="fill:%s">%s</text>', $x, $y + 14.5, tooth_code_color($code), e($code)) : '');
                };
                $svg .= sprintf('<g class="%s" data-tooth="%d" data-status="%s" data-treatment="%s" data-surfaces="%s" data-name="%s" tabindex="0" role="button" aria-label="Tooth %d, %s: %s">',
                    $classes, $no, e($status), e($treatment), e(implode(',', $surfaces)), e(tooth_name($no)), $no, e(tooth_name($no)), e($summary));
                $svg .= $box($status, $by) . $box($treatment, $by + 22);
                $svg .= sprintf('<text class="num" x="%.1f" y="%.1f" text-anchor="middle">%d</text>', $x, $ny, $no);
                $svg .= '<g class="ring">' . tooth_ring($no, $x, $ty, $surfaces, $fill) . '</g>';
                if ($gone) {
                    $svg .= sprintf('<path class="cross" d="M%1$.1f %2$.1f L%3$.1f %4$.1f M%3$.1f %2$.1f L%1$.1f %4$.1f"/>', $x - 15, $ty - 15, $x + 15, $ty + 15);
                }
                $svg .= '</g>';
            }
        }
    }
    $labels = [];
    foreach (TOOTH_CONDITIONS + TOOTH_RESTORATIONS + TOOTH_SURGERY as $code => [$label]) $labels[$code] = $label;

    return '<div class="chart-box dental-record" data-dental-chart data-labels="' . e(json_encode($labels)) . '">'
        . '<svg viewBox="0 0 1000 504" role="group" aria-label="Dental record chart">'
        . '<line class="midline" x1="500" y1="12" x2="500" y2="500"/>'
        . '<line class="archline" x1="60" y1="250" x2="940" y2="250"/>'
        . '<text class="side" x="18" y="38">STATUS</text><text class="side" x="18" y="54">RIGHT</text><text class="side" x="938" y="46">LEFT</text>'
        . '<text class="side" x="18" y="470">STATUS</text><text class="side" x="18" y="486">RIGHT</text><text class="side" x="938" y="478">LEFT</text>'
        . '<text class="group" x="18" y="98">TEMPORARY</text><text class="group" x="18" y="112">TEETH</text>'
        . '<text class="group" x="18" y="408">TEMPORARY</text><text class="group" x="18" y="422">TEETH</text>'
        . '<text class="group" transform="translate(32 250) rotate(-90)" text-anchor="middle">PERMANENT TEETH</text>'
        . $svg . '</svg></div>';
}

function render_chart_legend(): string
{
    $group = function (string $title, array $codes): string {
        $html = '<div class="legend-group"><h4>' . e($title) . '</h4><ul>';
        foreach ($codes as $code => [$label, $color]) {
            $html .= '<li><b style="color:' . $color . '">' . e($code) . '</b> ' . e($label) . '</li>';
        }
        return $html . '</ul></div>';
    };
    return '<div class="chart-legend">'
        . $group('Condition', TOOTH_CONDITIONS)
        . $group('Restorations & Prosthetics', TOOTH_RESTORATIONS)
        . $group('Surgery', TOOTH_SURGERY)
        . '<div class="legend-group"><h4>Surfaces</h4><ul>'
        . '<li><i class="swatch" style="background:' . tooth_code_color('D') . '"></i> Decayed surface</li>'
        . '<li><i class="swatch" style="background:' . tooth_code_color('Co') . '"></i> Restored surface</li>'
        . implode('', array_map(fn($k, $label) => '<li><b>' . $k . '</b> ' . e($label) . '</li>', array_keys(TOOTH_SURFACES), TOOTH_SURFACES))
        . '</ul></div></div>';
}

/** Teeth for the "Tooth #" pickers, permanent first. */
function tooth_options(): array
{
    $out = [];
    foreach (fdi_teeth() as $no) $out[$no] = $no . ' · ' . tooth_name($no);
    return $out;
}
