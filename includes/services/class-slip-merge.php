<?php
/**
 * Midland doc 3 → doc 4 merge engine (directive 03).
 *
 * Turns an uploaded doc 3 scan (the handwritten packer slip, one page per
 * order) into per-page 300-DPI backgrounds, composites the saved doc 4 driver
 * block onto each page's right region, and concatenates the result into one
 * print-ready PDF.
 *
 * Two host dependencies, both confirmed on live:
 *   - Imagick — PDF → image rasterization (NOT used anywhere else in the
 *     plugin; guarded with class_exists so a missing extension degrades to a
 *     clear failure rather than a fatal).
 *   - dompdf (vendored) — composites the doc 4 overlay over the background,
 *     mirroring the VAC-invoice background-image pattern
 *     (MealsDB_Invoice_Generator::serialize_vac_pdf_from_csv).
 *
 * Pairing is strictly POSITIONAL: doc4_orders[N] ↔ doc 3 page N+1. No content
 * guard / order-number stamp — collation is the team's responsibility via the
 * page numbers already printed on the docs (operator decision). The page-COUNT
 * check in validate_doc3() is the only guard.
 *
 * Fail-safe: every public method swallows its own \Throwable and returns a
 * sentinel ([] / '' / a structured failure), never propagating out.
 */
defined('ABSPATH') || exit;

class MealsDB_Slip_Merge {

    /** 300-DPI Letter landscape target (px). */
    private const RASTER_W = 3300;
    private const RASTER_H = 2550;
    private const DPI      = 300;

    /**
     * Validate an uploaded doc 3 PDF against a batch's order count. Used by the
     * AJAX layer to gate the Combine button.
     *
     * Valid IFF: the file is a readable PDF AND its page count === the expected
     * order count (orders are never multi-page → one doc 3 page per order). A
     * mismatch returns ok=false (Combine stays disabled — a deliberate BLOCK,
     * safe because the standalone doc 4 download lets the team proceed manually).
     *
     * @return array{ok:bool, page_count:int, reason:string}
     */
    public static function validate_doc3(string $pdf_path, int $expected_order_count): array {
        try {
            if ($pdf_path === '' || !is_readable($pdf_path)) {
                return ['ok' => false, 'page_count' => 0, 'reason' => 'File is not readable.'];
            }
            if (!self::is_pdf($pdf_path)) {
                return ['ok' => false, 'page_count' => 0, 'reason' => 'Uploaded file is not a PDF.'];
            }

            $pages = self::count_pdf_pages($pdf_path);
            if ($pages <= 0) {
                return ['ok' => false, 'page_count' => 0, 'reason' => 'Could not determine the PDF page count.'];
            }
            if ($pages !== $expected_order_count) {
                return [
                    'ok'         => false,
                    'page_count' => $pages,
                    'reason'     => sprintf(
                        'Uploaded %d page(s) but the batch has %d order(s). They must match.',
                        $pages,
                        $expected_order_count
                    ),
                ];
            }

            return ['ok' => true, 'page_count' => $pages, 'reason' => ''];
        } catch (\Throwable $e) {
            self::log_error('validate_doc3', $e);
            return ['ok' => false, 'page_count' => 0, 'reason' => 'Validation failed.'];
        }
    }

    /**
     * Composite the saved doc 4 driver blocks onto an uploaded doc 3 scan and
     * return the finished merged PDF bytes. Returns '' on any failure (the
     * caller treats '' as "combine failed").
     *
     * @param array<int,array> $doc4_orders persisted, positional driver blocks
     * @param string           $doc3_path   absolute path to the uploaded doc 3 PDF
     */
    public static function combine(array $doc4_orders, string $doc3_path): string {
        $bg_paths = [];
        try {
            $orders = array_values($doc4_orders);
            if (empty($orders) || $doc3_path === '' || !is_readable($doc3_path)) {
                return '';
            }

            // Defensive re-check of the positional invariant (the AJAX upload
            // already validated, but combine must not composite a mismatch).
            $bg_paths = self::rasterize_doc3($doc3_path);
            if (count($bg_paths) !== count($orders)) {
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'  => 'error',
                        'category'  => 'slip_batch',
                        'subsystem' => 'slip_merge',
                        'event'     => 'combine.page_count_mismatch',
                        'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'   => sprintf('Doc 3 rasterized to %d page(s); batch has %d order(s).',
                            count($bg_paths), count($orders)),
                    ]);
                }
                return '';
            }

            return self::render_overlay_pdf($bg_paths, $orders);
        } catch (\Throwable $e) {
            self::log_error('combine', $e);
            return '';
        } finally {
            // Always clean up the scratch backgrounds.
            foreach ($bg_paths as $p) {
                if (is_string($p) && $p !== '' && is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }

    /**
     * Rasterize a doc 3 PDF to per-page 300-DPI Letter-landscape JPEG
     * backgrounds. Returns an ordered list of file paths (one per page), or []
     * if Imagick is unavailable or the read fails.
     *
     * Orientation is DETECTED, not assumed: a page that reads as portrait
     * (height > width) is rotated +90° to upright landscape (the reference
     * sample was Letter portrait). Every page is normalized to landscape before
     * compositing.
     *
     * @return array<int,string>
     */
    public static function rasterize_doc3(string $pdf_path): array {
        if ($pdf_path === '' || !is_readable($pdf_path)) {
            return [];
        }
        if (!class_exists('Imagick')) {
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error',
                    'category'  => 'slip_batch',
                    'subsystem' => 'slip_merge',
                    'event'     => 'rasterize.no_imagick',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => 'Imagick is not installed; cannot rasterize doc 3.',
                ]);
            }
            return [];
        }

        $tmp_dir = self::tmp_dir();
        if ($tmp_dir === null) {
            return [];
        }

        $paths = [];
        try {
            $token = self::token();
            // Read at 300 DPI so each page rasterizes to ~3300x2550.
            $doc = new \Imagick();
            $doc->setResolution(self::DPI, self::DPI);
            $doc->readImage($pdf_path);

            $page = 0;
            foreach ($doc as $frame) {
                $frame->setImageFormat('jpeg');
                $frame->setImageCompressionQuality(90);

                // Orientation: rotate portrait pages up to landscape.
                if ($frame->getImageHeight() > $frame->getImageWidth()) {
                    $frame->rotateImage(new \ImagickPixel('white'), 90);
                }

                // Normalize to the exact 300-DPI Letter-landscape canvas so the
                // overlay coordinates line up regardless of the scan's own size.
                $frame->setImageBackgroundColor(new \ImagickPixel('white'));
                $frame->thumbnailImage(self::RASTER_W, self::RASTER_H, true);
                $frame->extentImage(
                    self::RASTER_W,
                    self::RASTER_H,
                    (int) ((self::RASTER_W - $frame->getImageWidth()) / 2),
                    (int) ((self::RASTER_H - $frame->getImageHeight()) / 2)
                );

                $dest = trailingslashit($tmp_dir) . 'doc3-' . $token . '-' . $page . '.jpg';
                $frame->writeImage($dest);
                @chmod($dest, 0600);
                $paths[] = $dest;
                $page++;
            }
            $doc->clear();
            $doc->destroy();
        } catch (\Throwable $e) {
            self::log_error('rasterize_doc3', $e);
            // Drop any partial output so the caller sees a clean failure.
            foreach ($paths as $p) {
                if (is_file($p)) { @unlink($p); }
            }
            return [];
        }

        return $paths;
    }

    // ----------------------------------------------------------------- //
    //  Internals
    // ----------------------------------------------------------------- //

    /**
     * Build one dompdf document: each page is the doc 3 background JPEG with the
     * matching doc 4 driver block absolutely positioned over the right region
     * (the VAC background-image pattern). Returns PDF bytes, or '' on failure.
     */
    private static function render_overlay_pdf(array $bg_paths, array $orders): string {
        if (!class_exists('Dompdf\\Dompdf')) {
            return '';
        }

        $left  = MealsDB_Slip_PDF_Generator::DOC4_BLOCK_LEFT_IN;
        $top   = MealsDB_Slip_PDF_Generator::DOC4_BLOCK_TOP_IN;
        $width = MealsDB_Slip_PDF_Generator::DOC4_BLOCK_WIDTH_IN;

        $pages_html = '';
        $count      = count($bg_paths);
        foreach ($bg_paths as $i => $bg) {
            $order   = is_array($orders[$i] ?? null) ? $orders[$i] : [];
            // Shared single source of truth for the block CONTENT (skips empty
            // fields). NO divider drawn — the doc 3 background already has one.
            $block   = MealsDB_Slip_PDF_Generator::driver_block_inner_html($order);
            $bg_url  = 'file://' . $bg;
            $break   = ($i === $count - 1) ? '' : ' merge-break';

            $pages_html .= '<div class="merge-page' . $break . '" '
                . 'style="background-image:url(\'' . htmlspecialchars($bg_url, ENT_QUOTES) . '\');">'
                . '<div class="d4-block">' . $block . '</div>'
                . '</div>';
        }

        $css = <<<CSS
@page { size: letter landscape; margin: 0; }
body { font-family: Helvetica, Arial, sans-serif; color: #000; margin: 0; padding: 0; }
.merge-page {
    position: relative; width: 11in; height: 8.5in; overflow: hidden;
    background-repeat: no-repeat; background-position: 0 0; background-size: 11in 8.5in;
}
.merge-break { page-break-after: always; }
.d4-block { position: absolute; left: {$left}in; top: {$top}in; width: {$width}in; font-size: 12pt; line-height: 1.5; }
.d4-block .d4-collect { font-size: 16pt; font-weight: bold; margin-bottom: 0.08in; }
.d4-block .d4-name    { font-size: 16pt; font-weight: bold; }
.d4-block .d4-addr    { font-size: 12pt; }
.d4-block .d4-phone   { font-size: 12pt; }
CSS;

        $html = "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$pages_html}</body></html>";

        // chroot to the backgrounds' directory so dompdf may read the file://
        // images (mirrors the VAC pattern; isRemoteEnabled stays false).
        $chroot = dirname((string) reset($bg_paths));

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', $chroot);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        $out = $dompdf->output();
        return is_string($out) ? $out : '';
    }

    /** Magic-byte PDF check (don't trust extension/MIME alone). */
    private static function is_pdf(string $path): bool {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 5);
        fclose($fh);
        return strncmp($head, '%PDF-', 5) === 0;
    }

    /**
     * Count PDF pages. Prefers Imagick (authoritative); falls back to a raw
     * page-object heuristic when Imagick is unavailable (keeps validate_doc3
     * usable off the live host). The heuristic counts /Type /Page objects,
     * which is correct for the simple, uncompressed scanner output doc 3s are.
     */
    private static function count_pdf_pages(string $pdf_path): int {
        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick();
                $im->pingImage($pdf_path);
                $n = $im->getNumberImages();
                $im->clear();
                $im->destroy();
                if ($n > 0) {
                    return (int) $n;
                }
            } catch (\Throwable $e) {
                // fall through to the heuristic
            }
        }

        $bytes = @file_get_contents($pdf_path);
        if (!is_string($bytes) || $bytes === '') {
            return 0;
        }
        // Count page objects: "/Type /Page" but not "/Type /Pages" (the tree
        // root). \b after Page rejects the plural.
        if (preg_match_all('#/Type\s*/Page\b#', $bytes, $m)) {
            return count($m[0]);
        }
        return 0;
    }

    /** Protected scratch dir for rasterized backgrounds. */
    private static function tmp_dir(): ?string {
        if (class_exists('MealsDB_Slip_Batch')) {
            return MealsDB_Slip_Batch::storage_dir('tmp');
        }
        return null;
    }

    /** Short random token for scratch filenames. */
    private static function token(): string {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            return substr(md5((string) getmypid() . (string) memory_get_usage()), 0, 12);
        }
    }

    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Slip_Merge] ' . $op . ' failed: ' . $e->getMessage());
        } else {
            error_log('[MealsDB Slip_Merge] ' . $op . ' failed: ' . $e->getMessage());
        }
    }
}
