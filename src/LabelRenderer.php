<?php

/**
 * Asset Label - QR code inventory labels for GLPI on Brother QL printers.
 *
 * @author  Krzysztof Andrzej Błachut vel Bakus
 * @license 0BSD
 * @link    https://github.com/Bakus/glpi-assetlabel
 */

declare(strict_types=1);

namespace GlpiPlugin\Assetlabel;

use Com\Tecnick\Barcode\Barcode;
use Com\Tecnick\Barcode\Exception as BarcodeException;
use GdImage;
use GlpiPlugin\Assetlabel\Format\LabelFormat;

/**
 * Draws a label as a black & white / grayscale bitmap at the printer's native resolution.
 *
 * Layout: QR code on the left (full content height), optional logo and text lines on the right.
 */
final class LabelRenderer
{
    private const FONT_REGULAR = __DIR__ . '/../fonts/NotoSans-Regular.ttf';
    private const FONT_BOLD    = __DIR__ . '/../fonts/NotoSans-Bold.ttf';

    /** Quiet zone around the QR code, in modules (the label margin adds to it). */
    private const QR_QUIET_ZONE = 2;
    /** Fallback for small labels: lowest error correction and a narrower quiet zone. */
    private const QR_SMALL_ECC = 'L';
    private const QR_SMALL_QUIET_ZONE = 1;
    /** Smallest QR module that still scans reliably, in millimetres. */
    private const QR_MIN_MODULE_MM = 0.25;
    /**
     * Largest share of the content width the QR code may take: the share it has on DK-11209
     * (273 of 696 px), so that format itself is not limited.
     */
    private const QR_MAX_WIDTH_RATIO = 0.393;
    private const GAP_MM = 2.0;
    private const LOGO_MAX_HEIGHT_MM = 6.0;
    private const LINE_HEIGHT = 1.3; // relative to font size
    /** Content height the base font sizes are designed for (DK-11209); taller labels get larger text. */
    private const BASE_CONTENT_HEIGHT_MM = 23.0;
    private const ELLIPSIS = '…';

    /**
     * @param LabelFormat $format     label format (size, margins and resolution)
     * @param string      $qr_ecc     preferred QR code error correction level: L, M, Q or H
     * @param bool        $sharp_text draw text without anti-aliasing
     */
    public function __construct(
        private readonly LabelFormat $format,
        private readonly string $qr_ecc = 'M',
        private readonly bool $sharp_text = false,
    ) {
    }

    /**
     * Draws the whole label: white background, QR code, optional logo and text lines.
     *
     * @param LabelData $data           content of the label
     * @param bool      $show_safe_area draw a gray frame around the content area (margin check)
     *
     * @return GdImage truecolor image of the label size at the format's dpi
     *
     * @throws LabelException when the QR code content is empty or too long for the label
     */
    public function render(LabelData $data, bool $show_safe_area = false): GdImage
    {
        $width  = $this->px($this->format->getWidthMm());
        $height = $this->px($this->format->getHeightMm());

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, $this->gray($image, 255));
        imageresolution($image, $this->format->getDpi(), $this->format->getDpi());

        // Content box (inside the safe margins)
        $left   = $this->px($this->format->getMarginXMm());
        $top    = $this->px($this->format->getMarginYMm());
        $right  = $width - $left;
        $bottom = $height - $top;

        // QR code: as large as the content height allows, but leaving most of the width for text
        $qr_side = min($bottom - $top, (int) (($right - $left) * self::QR_MAX_WIDTH_RATIO));
        $this->drawQrCode($image, $data->qr_payload, $left, $top + intdiv($bottom - $top - $qr_side, 2), $qr_side);

        $text_left  = $left + $qr_side + $this->px(self::GAP_MM);
        $text_width = $right - $text_left;
        $text_top   = $top;

        if ($data->logo_path !== null) {
            $logo_max_height = min($this->px(self::LOGO_MAX_HEIGHT_MM), intdiv($bottom - $top, 4));
            $logo_height = $this->drawLogo($image, $data->logo_path, $text_left, $top, $text_width, $logo_max_height);
            if ($logo_height > 0) {
                $text_top += $logo_height + $this->px(1.0);
            }
        }

        $content_height_mm = $this->format->getHeightMm() - 2 * $this->format->getMarginYMm();
        $max_scale = max(1.0, $content_height_mm / self::BASE_CONTENT_HEIGHT_MM);
        $this->drawTextLines(
            $image,
            $this->buildLines($data),
            $text_left,
            $text_top,
            $text_width,
            $bottom - $text_top,
            $max_scale,
        );

        if ($show_safe_area) {
            imagerectangle($image, $left, $top, $right - 1, $bottom - 1, $this->gray($image, 128));
        }

        return $image;
    }

    /**
     * Text lines in printing order with their base and minimum font sizes (pt).
     * Whitespace is collapsed and empty lines are skipped.
     *
     * @param LabelData $data content of the label
     *
     * @return list<array{text: string, bold: bool, max_pt: float, min_pt: float}>
     */
    private function buildLines(LabelData $data): array
    {
        $lines = [];
        $add = static function (string $text, bool $bold, float $max_pt, float $min_pt) use (&$lines): void {
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text !== '') {
                $lines[] = ['text' => $text, 'bold' => $bold, 'max_pt' => $max_pt, 'min_pt' => $min_pt];
            }
        };

        $add($data->name, true, 12, 7);
        $add($data->otherserial, true, 9, 6);
        $add(
            implode(' · ', array_filter([$data->type, $data->entity], static fn($v) => trim($v) !== '')),
            false,
            8,
            5.5,
        );
        $add($data->extra, false, 8, 5.5);

        return $lines;
    }

    /**
     * Stacks the lines vertically centred in the given box. Fonts are scaled together to fill
     * the height (up to $max_scale), then each line is shrunk / ellipsized to fit in width.
     * On small labels, the last lines are dropped when even the minimum font sizes do not fit.
     *
     * @param GdImage $image target image
     * @param list<array{text: string, bold: bool, max_pt: float, min_pt: float}> $lines lines from buildLines()
     * @param int $x left edge of the box, in px
     * @param int $y top edge of the box, in px
     * @param int $width box width, in px
     * @param int $height box height, in px
     * @param float $max_scale largest font scale factor
     *
     * @return void
     */
    private function drawTextLines(
        GdImage $image,
        array $lines,
        int $x,
        int $y,
        int $width,
        int $height,
        float $max_scale,
    ): void {
        $min_height = fn(array $lines): float
            => $this->ptToPx(array_sum(array_column($lines, 'min_pt'))) * self::LINE_HEIGHT;
        while (count($lines) > 1 && $min_height($lines) > $height) {
            array_pop($lines);
        }
        if ($lines === []) {
            return;
        }

        $total_pt = array_sum(array_column($lines, 'max_pt'));
        $scale = min($max_scale, $height / ($this->ptToPx($total_pt) * self::LINE_HEIGHT));

        $sizes = [];
        foreach ($lines as $i => $line) {
            $sizes[$i] = max($line['min_pt'], $line['max_pt'] * $scale);
        }

        $block_height = (int) round($this->ptToPx(array_sum($sizes)) * self::LINE_HEIGHT);
        $line_top = $y + max(0, intdiv($height - $block_height, 2));
        // A negative colour disables anti-aliasing in GD; black (index 0) cannot be negated, so use 1,1,1.
        $color = $this->sharp_text ? -$this->gray($image, 1) : $this->gray($image, 0);

        $first_pt = null;
        foreach ($lines as $i => $line) {
            $font = $line['bold'] ? self::FONT_BOLD : self::FONT_REGULAR;
            // Long texts shrink to at most half their size (never below the minimum) before being cut
            $min_pt = min($sizes[$i], max($line['min_pt'], $sizes[$i] * 0.5));
            // Scaled size, capped at the larger of the base size and the first line's size,
            // so on enlarged labels no line grows larger than the name
            $start_pt = min($sizes[$i], max($line['max_pt'], $first_pt ?? $sizes[$i]));
            [$text, $pt] = $this->fitText($line['text'], $font, $start_pt, $min_pt, $width);
            $first_pt ??= $pt;

            $em = $this->ptToPx($sizes[$i]);
            $baseline = (int) round($line_top + $em * 0.95);
            imagettftext($image, $this->gdFontSize($pt), 0, $x, $baseline, $color, $font, $text);

            $line_top += (int) round($em * self::LINE_HEIGHT);
        }
    }

    /**
     * Shrinks the font down to $min_pt, then cuts the text with an ellipsis until it fits.
     *
     * @param string $text      text to fit
     * @param string $font      TrueType font file
     * @param float  $pt        starting font size, in pt
     * @param float  $min_pt    smallest font size, in pt
     * @param int    $max_width available width, in px
     *
     * @return array{0: string, 1: float} [text, font size in pt]
     */
    private function fitText(string $text, string $font, float $pt, float $min_pt, int $max_width): array
    {
        while ($pt > $min_pt && $this->textWidth($text, $font, $pt) > $max_width) {
            $pt = max($min_pt, $pt - 0.5);
        }

        if ($this->textWidth($text, $font, $pt) <= $max_width) {
            return [$text, $pt];
        }

        $length = mb_strlen($text);
        while (
            $length > 1
            && $this->textWidth(mb_substr($text, 0, $length) . self::ELLIPSIS, $font, $pt) > $max_width
        ) {
            $length--;
        }
        return [rtrim(mb_substr($text, 0, $length)) . self::ELLIPSIS, $pt];
    }

    /**
     * Rendered width of a text.
     *
     * @param string $text text to measure
     * @param string $font TrueType font file
     * @param float  $pt   font size, in pt
     *
     * @return int width in px, 0 when GD cannot measure it
     */
    private function textWidth(string $text, string $font, float $pt): int
    {
        $box = imagettfbbox($this->gdFontSize($pt), 0, $font, $text);
        return $box === false ? 0 : max($box[2], $box[4]);
    }

    /**
     * Draws the QR code centred in a square, with whole-pixel modules for sharp edges.
     *
     * @param GdImage $image   target image
     * @param string  $payload QR code content
     * @param int     $x       left edge of the square, in px
     * @param int     $y       top edge of the square, in px
     * @param int     $side    side of the square, in px
     *
     * @return void
     *
     * @throws LabelException when the content is empty, too long for a QR code or too long
     *                        to be printed with modules of at least QR_MIN_MODULE_MM
     */
    private function drawQrCode(GdImage $image, string $payload, int $x, int $y, int $side): void
    {
        if ($payload === '') {
            throw new LabelException(__('The QR code content is empty.', 'assetlabel'));
        }

        // When the modules would be too small (small labels, long content), retry with less
        // error correction and a narrower quiet zone, which gives fewer, larger modules.
        $min_module_px = max(1, $this->px(self::QR_MIN_MODULE_MM));
        foreach (
            [
                [$this->qr_ecc, self::QR_QUIET_ZONE],
                [self::QR_SMALL_ECC, self::QR_SMALL_QUIET_ZONE],
            ] as [$ecc, $quiet_zone]
        ) {
            try {
                $grid = (new Barcode())
                    ->getBarcodeObj('QRCODE,' . $ecc, $payload, -1, -1, 'black', [0, 0, 0, 0])
                    ->getGridArray('0', '1');
            } catch (BarcodeException) {
                throw new LabelException(__('The QR code content is too long.', 'assetlabel'));
            }
            $modules = count($grid);
            $module_px = intdiv($side, $modules + 2 * $quiet_zone);
            if ($module_px >= $min_module_px) {
                break;
            }
        }
        if ($module_px < $min_module_px) {
            throw new LabelException(
                __('The QR code content is too long to be printed legibly on this label.', 'assetlabel'),
            );
        }

        $offset = intdiv($side - $module_px * $modules, 2);
        $black = $this->gray($image, 0);
        foreach ($grid as $row => $cells) {
            foreach ($cells as $col => $cell) {
                if ($cell === '1') {
                    $mx = $x + $offset + $col * $module_px;
                    $my = $y + $offset + $row * $module_px;
                    imagefilledrectangle($image, $mx, $my, $mx + $module_px - 1, $my + $module_px - 1, $black);
                }
            }
        }
    }

    /**
     * Draws the logo scaled to fit the box, aligned top-left. Returns the drawn height.
     *
     * @param GdImage $image      target image
     * @param string  $path       PNG logo file
     * @param int     $x          left edge of the box, in px
     * @param int     $y          top edge of the box, in px
     * @param int     $max_width  box width, in px
     * @param int     $max_height box height, in px
     *
     * @return int drawn height in px, 0 when the file cannot be read as PNG
     */
    private function drawLogo(GdImage $image, string $path, int $x, int $y, int $max_width, int $max_height): int
    {
        $logo = @imagecreatefrompng($path);
        if ($logo === false) {
            return 0;
        }

        $ratio  = min($max_width / imagesx($logo), $max_height / imagesy($logo));
        $width  = max(1, (int) round(imagesx($logo) * $ratio));
        $height = max(1, (int) round(imagesy($logo) * $ratio));

        imagecopyresampled($image, $logo, $x, $y, 0, 0, $width, $height, imagesx($logo), imagesy($logo));
        return $height;
    }

    /**
     * Allocates a gray colour.
     *
     * @param GdImage $image target image
     * @param int     $level 0 (black) to 255 (white)
     *
     * @return int colour identifier
     */
    private function gray(GdImage $image, int $level): int
    {
        return imagecolorallocate($image, $level, $level, $level);
    }

    /**
     * Converts millimetres to whole pixels at the format's dpi.
     *
     * @param float $mm length in mm
     *
     * @return int length in px
     */
    private function px(float $mm): int
    {
        return (int) round($mm * $this->format->getDpi() / 25.4);
    }

    /**
     * Converts a font size in points to pixels at the format's dpi.
     *
     * @param float $pt size in pt
     *
     * @return float size in px
     */
    private function ptToPx(float $pt): float
    {
        return $pt * $this->format->getDpi() / 72;
    }

    /**
     * GD interprets font sizes as points at 96 dpi.
     *
     * @param float $pt font size in pt at the format's dpi
     *
     * @return float font size to pass to GD
     */
    private function gdFontSize(float $pt): float
    {
        return $pt * $this->format->getDpi() / 96;
    }
}
