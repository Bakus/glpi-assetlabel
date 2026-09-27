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
 * Brother DK-11202 die-cut label, 62 × 100 mm, for QL-series printers (300 dpi).
 *
 * Read in landscape (100 mm wide): the 62 mm side is the tape width. Brother's printable
 * area is 696 × 1109 dots (~58.9 × 93.9 mm), which gives ~1.5 mm margins on the long edges
 * and ~3 mm margins on the short edges.
 */
final class Dk11202 extends DieCutLabel
{
    /**
     * Passes the name, size, tape width and margins of this label to DieCutLabel.
     */
    public function __construct()
    {
        parent::__construct('DK-11202 (62 × 100 mm)', 100.0, 62.0, 62.0, 3.0, 1.5);
    }
}
