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

use GlpiPlugin\Assetlabel\Format\ContinuousTape;
use GlpiPlugin\Assetlabel\Format\FormatRegistry;

/**
 * State of the printer and of the loaded paper, as reported over IPP.
 */
final readonly class PrinterStatus
{
    public const STATE_IDLE       = 3;
    public const STATE_PROCESSING = 4;
    public const STATE_STOPPED    = 5;

    /**
     * Attributes asked from the printer (Get-Printer-Attributes).
     */
    public const ATTRIBUTES = [
        'printer-make-and-model',
        'printer-state',
        'printer-state-reasons',
        'document-format-supported',
        'urf-supported',
        'finishings-supported',
        'print-quality-supported',
        'media-ready',
        'media-col-ready',
    ];

    /**
     * @param string       $model           make and model, e.g. "Brother QL-810W"
     * @param int          $state           STATE_* value (0 when unknown)
     * @param list<string> $state_reasons   IPP reasons such as "media-empty" ("none" left out)
     * @param bool         $supports_urf    whether the printer accepts AirPrint raster (image/urf)
     * @param list<string> $urf_features    AirPrint raster capabilities, e.g. "W8" (8-bit gray), "RS300" (dpi)
     * @param list<int>    $finishings      supported finishings (IPP enum values, e.g. 60 = cut after each page)
     * @param list<int>    $qualities       supported print qualities (IPP enum values: 3 draft, 4 normal, 5 high)
     * @param string       $media           IPP name of the loaded paper, empty when unknown
     * @param bool         $media_is_roll   whether the loaded paper is continuous tape
     * @param float        $media_width_mm  width of the paper (short side of die-cut labels), 0 when unknown
     * @param float        $media_length_mm length of die-cut labels (long side), 0 for tapes or when unknown
     */
    public function __construct(
        public string $model,
        public int $state,
        public array $state_reasons,
        public bool $supports_urf,
        public array $urf_features,
        public array $finishings,
        public array $qualities,
        public string $media,
        public bool $media_is_roll,
        public float $media_width_mm,
        public float $media_length_mm,
    ) {
    }

    /**
     * Builds the status from a decoded Get-Printer-Attributes response.
     * The paper size comes from media-col-ready, in hundredths of mm (ranges give their lower bound).
     *
     * @param array<string, list<mixed>> $attributes decoded attributes (Ipp::decode())
     *
     * @return self
     */
    public static function fromAttributes(array $attributes): self
    {
        $collection = $attributes['media-col-ready'][0] ?? [];
        $size       = is_array($collection) ? ($collection['media-size'][0] ?? []) : [];
        $dimension  = static function (string $name) use ($size): float {
            $value = is_array($size) ? ($size[$name][0] ?? 0) : 0;
            $value = is_array($value) ? $value[0] : $value;
            return is_int($value) ? $value / 100 : 0.0;
        };
        $is_roll = is_array($collection) && ($collection['media-type'][0] ?? '') === 'roll';

        return new self(
            model: (string) ($attributes['printer-make-and-model'][0] ?? ''),
            state: (int) ($attributes['printer-state'][0] ?? 0),
            state_reasons: array_values(array_diff(
                array_filter($attributes['printer-state-reasons'] ?? [], 'is_string'),
                ['none'],
            )),
            supports_urf: in_array('image/urf', $attributes['document-format-supported'] ?? [], true),
            urf_features: array_values(array_filter($attributes['urf-supported'] ?? [], 'is_string')),
            finishings: array_values(array_filter($attributes['finishings-supported'] ?? [], 'is_int')),
            qualities: array_values(array_filter($attributes['print-quality-supported'] ?? [], 'is_int')),
            media: (string) ($attributes['media-ready'][0] ?? ''),
            media_is_roll: $is_roll,
            media_width_mm: $dimension('x-dimension'),
            media_length_mm: $is_roll ? 0.0 : $dimension('y-dimension'),
        );
    }

    /**
     * Whether the printer accepts 8-bit grayscale AirPrint raster (URF feature "W8"),
     * the only kind of image the plugin sends.
     *
     * @return bool
     */
    public function supportsGray8(): bool
    {
        return $this->supports_urf && in_array('W8', $this->urf_features, true);
    }

    /**
     * Resolutions of AirPrint raster images (URF feature "RS", e.g. "RS300" or "RS300-600").
     *
     * @return list<int> dots per inch
     */
    public function getResolutions(): array
    {
        foreach ($this->urf_features as $feature) {
            if (str_starts_with($feature, 'RS')) {
                return array_map('intval', explode('-', substr($feature, 2)));
            }
        }
        return [];
    }

    /**
     * Label format matching the loaded paper: a die-cut label of the same size, or a
     * continuous tape of the same width.
     *
     * @return string|null format id (FormatRegistry), null when no format matches
     */
    public function findFormatId(): ?string
    {
        if ($this->media_width_mm <= 0) {
            return null;
        }
        foreach (FormatRegistry::all() as $id => $format) {
            if ($format instanceof ContinuousTape) {
                $matches = $this->media_is_roll
                    && abs($format->getTapeWidthMm() - $this->media_width_mm) < 0.5;
            } else {
                $sides = [$format->getWidthMm(), $format->getHeightMm()];
                $matches = !$this->media_is_roll
                    && abs(min($sides) - $this->media_width_mm) < 0.5
                    && abs(max($sides) - $this->media_length_mm) < 0.5;
            }
            if ($matches) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Loaded paper for messages: the matching label type, otherwise the size or the IPP name.
     *
     * @return string
     */
    public function getMediaDescription(): string
    {
        $id = $this->findFormatId();
        if ($id !== null) {
            return FormatRegistry::get($id)->getName();
        }
        if ($this->media_width_mm > 0) {
            return $this->media_is_roll
                ? sprintf(__('%s mm continuous tape', 'assetlabel'), round($this->media_width_mm, 1))
                : sprintf('%s × %s mm', round($this->media_width_mm, 1), round($this->media_length_mm, 1));
        }
        return $this->media !== '' ? $this->media : __('unknown', 'assetlabel');
    }

    /**
     * Translated printer state, followed by the reasons reported by the printer.
     *
     * @return string
     */
    public function getStateDescription(): string
    {
        $state = match ($this->state) {
            self::STATE_IDLE       => __('ready', 'assetlabel'),
            self::STATE_PROCESSING => __('printing', 'assetlabel'),
            self::STATE_STOPPED    => __('stopped', 'assetlabel'),
            default                => __('unknown', 'assetlabel'),
        };
        return $this->state_reasons === [] ? $state : sprintf('%s (%s)', $state, implode(', ', $this->state_reasons));
    }
}
