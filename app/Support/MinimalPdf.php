<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dependency-free minimal PDF writer (Phase 18B).
 *
 * Produces a valid single-page PDF byte string from a list of text lines, with no
 * third-party renderer (no dompdf/snappy/tcpdf — adding one would be a pinned-stack
 * change requiring sign-off). It emits the PDF object graph (catalog → pages → page →
 * content stream) with a correct cross-reference table so the output is a real,
 * openable PDF. Used for generated receipt documents through the Phase 10F file
 * domain. Text is escaped for the PDF string syntax; callers pass only safe,
 * already-masked content (never a full reference or secret).
 */
final class MinimalPdf
{
    /**
     * @param  list<string>  $lines
     */
    public static function fromLines(array $lines, string $title = 'Receipt'): string
    {
        $content = self::contentStream($lines);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
        $objects[4] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 ".$count."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size ".$count." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function contentStream(array $lines): string
    {
        $y = 800;
        $stream = "BT\n/F1 12 Tf\n";
        foreach ($lines as $line) {
            $stream .= '1 0 0 1 50 '.$y." Tm\n(".self::escape($line).") Tj\n";
            $y -= 18;
        }
        $stream .= 'ET';

        return $stream;
    }

    private static function escape(string $text): string
    {
        // Keep to printable ASCII; escape the PDF string metacharacters.
        $text = preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
