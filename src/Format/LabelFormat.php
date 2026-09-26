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
 * Physical label format. All lengths are in millimetres, as seen when the label is read
 * (die-cut labels are laid out with the long side horizontal).
 */
interface LabelFormat
{
    /**
     * Name shown in the configuration and under previews.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Label width as read, in mm.
     *
     * @return float
     */
    public function getWidthMm(): float;

    /**
     * Label height as read, in mm.
     *
     * @return float
     */
    public function getHeightMm(): float;

    /**
     * Rendering resolution; should match the printer's native resolution.
     *
     * @return int dots per inch
     */
    public function getDpi(): int;

    /**
     * Margin kept free on the left and right edges (not printable / risk of cropping).
     *
     * @return float margin in mm
     */
    public function getMarginXMm(): float;

    /**
     * Margin kept free on the top and bottom edges.
     *
     * @return float margin in mm
     */
    public function getMarginYMm(): float;
}
