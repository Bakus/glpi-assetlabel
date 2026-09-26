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
 * Brother die-cut label with a fixed size, for QL-series printers (300 dpi).
 */
abstract class DieCutLabel implements LabelFormat
{
    /**
     * @param string $name        display name
     * @param float  $width_mm    width as read, in mm
     * @param float  $height_mm   height as read, in mm
     * @param float  $margin_x_mm left and right margin, in mm
     * @param float  $margin_y_mm top and bottom margin, in mm
     */
    public function __construct(
        private readonly string $name,
        private readonly float $width_mm,
        private readonly float $height_mm,
        private readonly float $margin_x_mm,
        private readonly float $margin_y_mm,
    ) {
    }

    /**
     * Display name given to the constructor.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Label width as read, in mm.
     *
     * @return float
     */
    public function getWidthMm(): float
    {
        return $this->width_mm;
    }

    /**
     * Label height as read, in mm.
     *
     * @return float
     */
    public function getHeightMm(): float
    {
        return $this->height_mm;
    }

    /**
     * Rendering resolution of QL-series printers.
     *
     * @return int dots per inch
     */
    public function getDpi(): int
    {
        return 300;
    }

    /**
     * Margin kept free on the left and right edges.
     *
     * @return float margin in mm
     */
    public function getMarginXMm(): float
    {
        return $this->margin_x_mm;
    }

    /**
     * Margin kept free on the top and bottom edges.
     *
     * @return float margin in mm
     */
    public function getMarginYMm(): float
    {
        return $this->margin_y_mm;
    }
}
