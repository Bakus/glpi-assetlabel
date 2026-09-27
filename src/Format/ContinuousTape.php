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
 * Brother continuous tape for QL-series printers (300 dpi), with the length set in the
 * settings. Brother's printable areas leave ~1.5 mm margins along the tape edges
 * (e.g. 696 of 732 dots on 62 mm tape, 306 of 342 dots on 29 mm tape) and ~3 mm margins
 * at both ends of the label.
 *
 * When the label is not longer than the tape width, it is read like DK-11209 (the tape
 * width is horizontal); longer labels are laid out in landscape (the length is horizontal).
 */
abstract class ContinuousTape implements LabelFormat
{
    private const SIDE_MARGIN_MM = 1.5; // along the tape edges
    private const END_MARGIN_MM  = 3.0; // at the start and end of the label

    /**
     * @param float $length_mm label length, in mm
     */
    final public function __construct(private readonly float $length_mm = 29.0)
    {
    }

    /**
     * Same tape with another label length.
     *
     * @param float $length_mm label length, in mm
     *
     * @return static
     */
    public function withLength(float $length_mm): static
    {
        return new static($length_mm);
    }

    /**
     * Width of the tape, in mm.
     *
     * @return float
     */
    abstract public function getTapeWidthMm(): float;

    /**
     * Label length along the tape, in mm.
     *
     * @return float
     */
    public function getLengthMm(): float
    {
        return $this->length_mm;
    }

    /**
     * Label width as read, in mm.
     *
     * @return float
     */
    public function getWidthMm(): float
    {
        return $this->isLandscape() ? $this->length_mm : $this->getTapeWidthMm();
    }

    /**
     * Label height as read, in mm.
     *
     * @return float
     */
    public function getHeightMm(): float
    {
        return $this->isLandscape() ? $this->getTapeWidthMm() : $this->length_mm;
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
        return $this->isLandscape() ? self::END_MARGIN_MM : self::SIDE_MARGIN_MM;
    }

    /**
     * Margin kept free on the top and bottom edges.
     *
     * @return float margin in mm
     */
    public function getMarginYMm(): float
    {
        return $this->isLandscape() ? self::SIDE_MARGIN_MM : self::END_MARGIN_MM;
    }

    /**
     * Whether the label is longer than the tape width, so the length is horizontal.
     *
     * @return bool
     */
    private function isLandscape(): bool
    {
        return $this->length_mm > $this->getTapeWidthMm();
    }
}
