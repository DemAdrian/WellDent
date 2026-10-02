<?php

/**
 * Small PDF writer for the printable reports: A4 portrait, the built-in Helvetica fonts (nothing to
 * install or download, so it works offline), stat boxes and tables that flow onto new pages.
 * Text is stored as WinAnsi, so it covers English/Filipino text; ₱ is written as "PHP".
 */
final class PdfReport
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 40; // page margin, points
    private const INK = '#1c2b2e';
    private const MUTED = '#6b7c80';
    private const LINE = '#e2ebe9';
    private const SOFT = '#f3f8f7';
    private const TEAL = '#1f8a80';

    /** Helvetica / Helvetica-Bold advance widths for ASCII 32–126 (per 1000 em). */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556,
        556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    private const BOLD_WIDTHS = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611,
        611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];
    /** WinAnsi characters past ASCII that the reports actually use: en dash, em dash, middle dot, ellipsis. */
    private const EXTRA_WIDTHS = ["\x96" => 556, "\x97" => 1000, "\xB7" => 278, "\x85" => 1000];

    private array $pages = [];
    private float $y = 0;

    public function __construct(
        private string $title,
        private string $subtitle,
        private string $org,
        private string $footer,
    ) {
        $this->addPage();
        $this->text(self::M, $this->y + 10, $this->org, 10, true, self::TEAL);
        $this->text(self::M, $this->y + 36, $this->title, 22, true);
        $this->text(self::M, $this->y + 54, $this->subtitle, 10, false, self::MUTED);
        $this->y += 70;
    }

    /** A row of summary boxes: each item is [label, value, note]. */
    public function stats(array $items): void
    {
        $gap = 10;
        $w = (self::W - 2 * self::M - $gap * (count($items) - 1)) / count($items);
        $this->ensure(66);
        foreach (array_values($items) as $i => [$label, $value, $note]) {
            $x = self::M + $i * ($w + $gap);
            $this->rect($x, $this->y, $w, 62, self::SOFT);
            $this->text($x + 10, $this->y + 16, $this->fit($label, $w - 20, 8.5, false), 8.5, false, self::MUTED);
            $size = 17; // shrink big amounts to fit rather than cut them off
            while ($size > 9 && $this->width($value, $size, false) > $w - 20) {
                $size -= .5;
            }
            $this->text($x + 10, $this->y + 38, $this->fit($value, $w - 20, $size, false), $size);
            $this->text($x + 10, $this->y + 53, $this->fit($note, $w - 20, 7.5, false), 7.5, false, self::MUTED);
        }
        $this->y += 62 + 24;
    }

    /**
     * A titled table. $cols: [label, share of the page width, 'L'|'R'].
     * A cell is a string, or [string, colour] to highlight it.
     */
    public function table(string $title, array $cols, array $rows, string $empty): void
    {
        $width = self::W - 2 * self::M;
        $total = array_sum(array_column($cols, 1));
        $xs = [];
        $x = self::M;
        foreach ($cols as $i => $c) {
            $xs[$i] = [$x, $c[1] / $total * $width];
            $x += $xs[$i][1];
        }

        $this->ensure(70); // keep the title with its header and first row
        $this->text(self::M, $this->y + 12, $title, 12, true);
        $this->y += 22;
        $this->tableHead($cols, $xs);

        if (!$rows) {
            $this->text(self::M + 6, $this->y + 16, $empty, 9, false, self::MUTED);
            $this->y += 26;
        }
        foreach ($rows as $row) {
            $lines = [];
            foreach ($cols as $i => $c) {
                $lines[$i] = $this->wrap((string) (is_array($row[$i]) ? $row[$i][0] : $row[$i]), $xs[$i][1] - 12, 9);
            }
            $h = 8 + 11 * max(array_map('count', $lines));
            if ($this->ensure($h)) {
                $this->tableHead($cols, $xs);
            }
            foreach ($cols as $i => $c) {
                $color = is_array($row[$i]) ? $row[$i][1] : self::INK;
                foreach ($lines[$i] as $n => $line) {
                    $tx = $c[2] === 'R' ? $xs[$i][0] + $xs[$i][1] - 6 - $this->width($line, 9, false) : $xs[$i][0] + 6;
                    $this->text($tx, $this->y + 14 + 11 * $n, $line, 9, false, $color);
                }
            }
            $this->y += $h;
            $this->line(self::M, $this->y, self::W - self::M, $this->y, self::LINE);
        }
        $this->y += 22;
    }

    /** Sends the PDF as a download and stops. */
    public function download(string $filename): never
    {
        $pdf = $this->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ---------- layout ----------

    private function tableHead(array $cols, array $xs): void
    {
        foreach ($cols as $i => [$label, , $align]) {
            $label = strtoupper($label);
            $tx = $align === 'R' ? $xs[$i][0] + $xs[$i][1] - 6 - $this->width($label, 7.5, true) : $xs[$i][0] + 6;
            $this->text($tx, $this->y + 11, $label, 7.5, true, self::MUTED);
        }
        $this->y += 17;
        $this->line(self::M, $this->y, self::W - self::M, $this->y, '#c9d8d5', .8);
    }

    /** Starts a new page when $h more points won't fit; returns whether it did. */
    private function ensure(float $h): bool
    {
        if ($this->y + $h <= self::H - self::M - 20) {
            return false;
        }
        $this->addPage();
        return true;
    }

    private function addPage(): void
    {
        $this->pages[] = '';
        $this->y = self::M;
    }

    // ---------- text ----------

    private static function encode(string $s): string
    {
        $s = str_replace('₱', 'PHP ', $s);
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    private function width(string $s, float $size, bool $bold): float
    {
        $table = $bold ? self::BOLD_WIDTHS : self::WIDTHS;
        $w = 0;
        foreach (str_split(self::encode($s)) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? $table[$o - 32] : (self::EXTRA_WIDTHS[$ch] ?? 556);
        }
        return $w * $size / 1000;
    }

    /** Cuts text to fit $w, ending in an ellipsis. */
    private function fit(string $s, float $w, float $size, bool $bold): string
    {
        if ($this->width($s, $size, $bold) <= $w) {
            return $s;
        }
        while ($s !== '' && $this->width($s . '…', $size, $bold) > $w) {
            $s = mb_substr($s, 0, -1);
        }
        return rtrim($s) . '…';
    }

    /** Word-wraps text into lines no wider than $w; over-long words are cut. */
    private function wrap(string $s, float $w, float $size): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($s)) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line === '' || $this->width($try, $size, false) <= $w) {
                $line = $try;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        $lines[] = $line;
        return array_map(fn ($l) => $this->fit($l, $w, $size, false), $lines);
    }

    // ---------- drawing (y is measured from the top of the page) ----------

    private function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $color = self::INK, ?int $page = null): void
    {
        $s = strtr(self::encode($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->draw(sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, self::rgb($color), $x, self::H - $y, $s), $page);
    }

    private function rect(float $x, float $y, float $w, float $h, string $fill): void
    {
        $this->draw(sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", self::rgb($fill), $x, self::H - $y - $h, $w, $h));
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $color, float $width = .5, ?int $page = null): void
    {
        $this->draw(sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::rgb($color), $width, $x1, self::H - $y1, $x2, self::H - $y2), $page);
    }

    private function draw(string $op, ?int $page = null): void
    {
        $this->pages[$page ?? array_key_last($this->pages)] .= $op;
    }

    private static function rgb(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return sprintf('%.3F %.3F %.3F', $r / 255, $g / 255, $b / 255);
    }

    // ---------- file ----------

    private function render(): string
    {
        $count = count($this->pages);
        $fy = self::H - self::M + 14;
        foreach (array_keys($this->pages) as $i) {
            $label = 'Page ' . ($i + 1) . ' of ' . $count;
            $this->line(self::M, $fy - 12, self::W - self::M, $fy - 12, self::LINE, .5, $i);
            $this->text(self::M, $fy, $this->footer, 7.5, false, self::MUTED, $i);
            $this->text(self::W - self::M - $this->width($label, 7.5, false), $fy, $label, 7.5, false, self::MUTED, $i);
        }

        // 1 catalog, 2 page tree, 3–4 fonts, 5 info, then a page + content object per page.
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Title ' . self::pdfString($this->title . ' – ' . $this->subtitle) . ' /Producer (WellDent+) /CreationDate (D:' . date('YmdHis') . ') >>',
        ];
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $pageId = 6 + $i * 2;
            $kids[] = "$pageId 0 R";
            $filter = '';
            if (function_exists('gzcompress')) {
                $content = gzcompress($content);
                $filter = ' /Filter /FlateDecode';
            }
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $pageId + 1);
            $objects[$pageId + 1] = '<< /Length ' . strlen($content) . $filter . " >>\nstream\n" . $content . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        return $out . 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    private static function pdfString(string $s): string
    {
        // Document info uses PDFDocEncoding, which puts dashes elsewhere than WinAnsi does.
        return '(' . strtr(self::encode(strtr($s, ['–' => '-', '—' => '-'])), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }
}
