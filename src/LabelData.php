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
 * Everything printed on one label. Empty strings are simply not printed.
 */
final readonly class LabelData
{
    /**
     * @param string      $qr_payload  QR code content; must not be empty when rendered
     * @param string      $name        item name
     * @param string      $otherserial inventory number
     * @param string      $type        translated item type name
     * @param string      $entity      entity name
     * @param string      $extra       additional label text
     * @param string|null $logo_path   PNG logo file, or null for no logo
     */
    public function __construct(
        public string $qr_payload,
        public string $name = '',
        public string $otherserial = '',
        public string $type = '',
        public string $entity = '',
        public string $extra = '',
        public ?string $logo_path = null,
    ) {
    }
}
