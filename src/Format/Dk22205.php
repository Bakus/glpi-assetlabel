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
 * Brother DK-22205 continuous paper tape, 62 mm wide.
 */
final class Dk22205 extends ContinuousTape
{
    /**
     * Width of the tape, in mm.
     *
     * @return float
     */
    protected function getTapeWidthMm(): float
    {
        return 62.0;
    }

    /**
     * Translated name of the tape.
     *
     * @return string
     */
    public function getName(): string
    {
        return __('DK-22205 (62 mm continuous tape)', 'assetlabel');
    }
}
