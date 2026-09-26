<?php

/**
 * Asset Label - QR code inventory labels for GLPI on Brother QL printers.
 *
 * @author  Krzysztof Andrzej Błachut vel Bakus
 * @license 0BSD
 * @link    https://github.com/Bakus/glpi-assetlabel
 */

declare(strict_types=1);

namespace GlpiPlugin\Assetlabel\Format;

/**
 * Brother DK-11208 die-cut label, 38 × 90 mm, for QL-series printers (300 dpi).
 *
 * Read in landscape (90 mm wide): the 38 mm side is the tape width. Brother's printable
 * area is 413 × 991 dots (~35 × 83.9 mm), which gives ~1.5 mm margins on the long edges
 * and ~3 mm margins on the short edges.
 */
final class Dk11208 extends DieCutLabel
{
    /**
     * Passes the name, size and margins of this label to DieCutLabel.
     */
    public function __construct()
    {
        parent::__construct('DK-11208 (38 × 90 mm)', 90.0, 38.0, 3.0, 1.5);
    }
}
