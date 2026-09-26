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
 * Generated label files waiting to be downloaded (bulk generation).
 *
 * Files live in GLPI_TMP_DIR (removed by the core "temp" cron task after one hour)
 * and are reachable only through a random token stored in the user's session.
 */
final readonly class Batch
{
    private const SESSION_KEY = 'plugin_assetlabel_batches';

    /**
     * @param string $token    random 32-character hex token
     * @param string $path     file in GLPI_TMP_DIR
     * @param string $filename download file name
     * @param string $back_url page to return to after the download
     */
    public function __construct(
        public string $token,
        public string $path,
        public string $filename,
        public string $back_url,
    ) {
    }

    /**
     * Writes the file to GLPI_TMP_DIR under a new random token and registers it in the session.
     * Also forgets the session entries whose file has already been removed.
     * Requires an active session.
     *
     * @param string $content  file content
     * @param string $filename download file name
     * @param string $back_url page to return to after the download
     *
     * @return self
     */
    public static function create(string $content, string $filename, string $back_url): self
    {
        // Forget batches whose file has already been removed by the cron task
        $_SESSION[self::SESSION_KEY] = array_filter(
            $_SESSION[self::SESSION_KEY] ?? [],
            static fn($batch) => is_array($batch) && is_file($batch['path'] ?? ''),
        );

        $token = bin2hex(random_bytes(16));
        $path  = GLPI_TMP_DIR . '/assetlabel_' . $token;
        file_put_contents($path, $content);

        $_SESSION[self::SESSION_KEY][$token] = [
            'path'     => $path,
            'filename' => $filename,
            'back_url' => $back_url,
        ];

        return new self($token, $path, $filename, $back_url);
    }

    /**
     * Looks up a batch of the current session whose file still exists.
     *
     * @param string $token batch token
     *
     * @return self|null null when the token is unknown or the file has been removed
     */
    public static function find(string $token): ?self
    {
        $batch = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!is_array($batch) || !is_file($batch['path'])) {
            return null;
        }
        return new self($token, $batch['path'], $batch['filename'], $batch['back_url']);
    }
}
