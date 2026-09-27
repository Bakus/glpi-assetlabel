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
 * Brother DK-22210 continuous paper tape, 29 mm wide.
 */
final class Dk22210 extends ContinuousTape
{
    /**
     * Width of the tape, in mm.
     *
     * @return float
     */
    public function getTapeWidthMm(): float
    {
        return 29.0;
    }

    /**
     * Translated name of the tape.
     *
     * @return string
     */
    public function getName(): string
    {
        return __('DK-22210 (29 mm continuous tape)', 'assetlabel');
    }
}
