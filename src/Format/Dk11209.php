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
 * Brother DK-11209 die-cut label, 62 × 29 mm, for QL-series printers (300 dpi).
 *
 * Brother's printable area for this label is 696 × 271 dots (~58.9 × 22.9 mm),
 * which gives ~1.5 mm side margins and ~3 mm top/bottom margins.
 */
final class Dk11209 extends DieCutLabel
{
    /**
     * Passes the name, size, tape width and margins of this label to DieCutLabel.
     */
    public function __construct()
    {
        parent::__construct('DK-11209 (62 × 29 mm)', 62.0, 29.0, 62.0, 1.5, 3.0);
    }
}
