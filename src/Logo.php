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

/**
 * The optional company logo, stored as a grayscale PNG in the plugin documents directory.
 */
final class Logo
{
    private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
    private const MAX_PIXELS = 25_000_000;
    private const MAX_SIDE_PX = 600;

    /**
     * Path of the stored logo.
     *
     * @return string|null null when no logo is stored
     */
    public static function getPath(): ?string
    {
        $path = self::getFilePath();
        return is_file($path) ? $path : null;
    }

    /**
     * Validates an uploaded image and stores a re-encoded grayscale copy.
     * Re-encoding also drops metadata and anything that is not pixel data.
     *
     * @param string $uploaded_file path of the uploaded temporary file
     *
     * @return void
     *
     * @throws LabelException when the file is not an accepted image or cannot be saved
     */
    public static function store(string $uploaded_file): void
    {
        $info = @getimagesize($uploaded_file);
        if (
            filesize($uploaded_file) > self::MAX_UPLOAD_BYTES
            || $info === false
            || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > self::MAX_PIXELS
        ) {
            throw new LabelException(
                __(
                    'The logo must be a PNG, JPEG, GIF or WebP image of at most 2 MB and 5000 × 5000 pixels.',
                    'assetlabel',
                ),
            );
        }

        $source = @imagecreatefromstring((string) file_get_contents($uploaded_file));
        if ($source === false) {
            throw new LabelException(__('The logo image could not be read.', 'assetlabel'));
        }

        // Flatten transparency on white, downscale if needed, then convert to grayscale.
        $ratio  = min(1, self::MAX_SIDE_PX / max(imagesx($source), imagesy($source)));
        $width  = max(1, (int) round(imagesx($source) * $ratio));
        $height = max(1, (int) round(imagesy($source) * $ratio));
        $logo   = imagecreatetruecolor($width, $height);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 255, 255));
        imagecopyresampled($logo, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        imagefilter($logo, IMG_FILTER_GRAYSCALE);

        self::ensureDirectory();
        if (!imagepng($logo, self::getFilePath(), 9)) {
            throw new LabelException(__('The logo could not be saved.', 'assetlabel'));
        }
    }

    /**
     * Removes the stored logo, if any.
     *
     * @return void
     */
    public static function delete(): void
    {
        $path = self::getPath();
        if ($path !== null) {
            unlink($path);
        }
    }

    /**
     * Creates the plugin documents directory when it does not exist yet.
     *
     * @return void
     */
    public static function ensureDirectory(): void
    {
        $directory = self::getDirectory();
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
    }

    /**
     * Plugin directory in GLPI_PLUGIN_DOC_DIR.
     *
     * @return string
     */
    private static function getDirectory(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/assetlabel';
    }

    /**
     * Path of the logo file, whether it exists or not.
     *
     * @return string
     */
    private static function getFilePath(): string
    {
        return self::getDirectory() . '/logo.png';
    }
}
