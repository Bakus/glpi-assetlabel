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

use Com\Tecnick\Pdf\Tcpdf;
use GdImage;
use GlpiPlugin\Assetlabel\Format\LabelFormat;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Turns rendered label bitmaps into files: PNG, PDF or ZIP to download, URF (AirPrint raster)
 * for direct printing.
 */
final class LabelFile
{
    /**
     * Encodes the image as PNG with maximum compression.
     *
     * @param GdImage $image label bitmap
     *
     * @return string PNG file content
     */
    public static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);
        return (string) ob_get_clean();
    }

    /**
     * One label per page, page size = label size, so the printer driver prints them 1:1.
     *
     * @param list<GdImage> $images label bitmaps, one per page
     * @param LabelFormat   $format format the images were rendered for (gives the page size)
     *
     * @return string PDF file content
     */
    public static function pdf(array $images, LabelFormat $format): string
    {
        $pdf = new Tcpdf(unit: 'mm');
        $pdf->setCreator('GLPI Asset Label');
        $pdf->setTitle('Labels');

        $width  = $format->getWidthMm();
        $height = $format->getHeightMm();
        foreach ($images as $image) {
            $page = $pdf->addPage([
                'width'       => $width,
                'height'      => $height,
                'orientation' => $width > $height ? 'L' : 'P',
                'margin'      => ['PL' => 0, 'PR' => 0, 'PT' => 0, 'PB' => 0, 'HB' => 0, 'FT' => 0],
            ]);
            $image_id = $pdf->image->add('@' . self::png($image));
            $pdf->page->addContent($pdf->image->getSetImage($image_id, 0, 0, $width, $height, $page['height']));
        }

        return $pdf->getOutPDFString();
    }

    /**
     * AirPrint raster (URF) for direct printing: 8-bit grayscale at the format's resolution,
     * one page per label. Labels whose width as read is not the tape width are rotated,
     * because the printer takes the image width as the width across the print head.
     *
     * @param list<GdImage> $images label bitmaps (grayscale), one per page
     * @param LabelFormat   $format format the images were rendered for
     *
     * @return string URF document
     */
    public static function urf(array $images, LabelFormat $format): string
    {
        $rotate = abs($format->getWidthMm() - $format->getTapeWidthMm()) > 0.01;

        $data = "UNIRAST\0" . pack('N', count($images));
        foreach ($images as $image) {
            if ($rotate) {
                $image = imagerotate($image, 90, 0xFFFFFF);
            }
            $width  = imagesx($image);
            $height = imagesy($image);
            // Page header: 8 bits per pixel, sGray, one-sided, normal quality, size and resolution
            $data .= pack('C4x8N3x8', 8, 0, 1, 4, $width, $height, $format->getDpi());

            $rows = [];
            for ($y = 0; $y < $height; $y++) {
                $row = '';
                for ($x = 0; $x < $width; $x++) {
                    // The label is grayscale, so the blue channel is the gray level
                    $row .= chr(imagecolorat($image, $x, $y) & 0xFF);
                }
                $rows[] = $row;
            }
            // Each line starts with its repeat count (0-255 extra copies), so blank areas stay small
            $y = 0;
            while ($y < $height) {
                $repeat = 0;
                while ($repeat < 255 && $y + $repeat + 1 < $height && $rows[$y + $repeat + 1] === $rows[$y]) {
                    $repeat++;
                }
                $data .= chr($repeat) . self::urfLine($rows[$y]);
                $y += $repeat + 1;
            }
        }
        return $data;
    }

    /**
     * Compresses one URF line: runs of equal pixels (count - 1, pixel) and literal
     * groups (257 - count, pixels), at most 128 pixels each.
     *
     * @param string $pixels one byte per pixel
     *
     * @return string
     */
    private static function urfLine(string $pixels): string
    {
        $data   = '';
        $length = strlen($pixels);
        $i      = 0;
        while ($i < $length) {
            $run = 1;
            while ($run < 128 && $i + $run < $length && $pixels[$i + $run] === $pixels[$i]) {
                $run++;
            }
            if ($run > 1) {
                $data .= chr($run - 1) . $pixels[$i];
                $i += $run;
                continue;
            }

            // Literal pixels up to the next run of two equal pixels
            $start = $i;
            do {
                $i++;
            } while ($i < $length && $i - $start < 128 && ($i + 1 === $length || $pixels[$i + 1] !== $pixels[$i]));
            $count = $i - $start;
            $data .= ($count === 1 ? "\0" : chr(257 - $count)) . substr($pixels, $start, $count);
        }
        return $data;
    }

    /**
     * Packs the images as PNG files into a ZIP archive, built in a temporary file
     * in GLPI_TMP_DIR.
     *
     * @param array<string, GdImage> $images file name (without extension) => image
     *
     * @return string ZIP file content
     *
     * @throws LabelException when the temporary archive cannot be written or read
     */
    public static function zip(array $images): string
    {
        $error = __(
            'The ZIP archive could not be created. Check that the GLPI temporary directory (files/_tmp) is writable.',
            'assetlabel',
        );

        $path = tempnam(GLPI_TMP_DIR, 'assetlabel_zip_');
        $zip  = new ZipArchive();
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new LabelException($error);
        }

        foreach ($images as $name => $image) {
            $zip->addFromString($name . '.png', self::png($image));
        }
        $content = $zip->close() ? file_get_contents($path) : false;
        @unlink($path);
        if ($content === false) {
            throw new LabelException($error);
        }
        return $content;
    }

    /**
     * MIME type of a generated file, from its extension (PDF, PNG, otherwise ZIP).
     *
     * @param string $filename file name with extension
     *
     * @return string
     */
    public static function mimeType(string $filename): string
    {
        return match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'pdf'   => 'application/pdf',
            'png'   => 'image/png',
            default => 'application/zip',
        };
    }

    /**
     * HTTP response with the file content, not cached by the browser.
     *
     * @param string $content  file content
     * @param string $filename file name with extension (also gives the MIME type)
     * @param bool   $inline   show in the browser instead of downloading
     *
     * @return Response
     */
    public static function response(string $content, string $filename, bool $inline): Response
    {
        return new Response($content, 200, [
            'Content-Type'        => self::mimeType($filename),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}
