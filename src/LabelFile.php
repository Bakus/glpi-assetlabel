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
 * Turns rendered label bitmaps into downloadable files.
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
