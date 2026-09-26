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

use CommonDBTM;
use Computer;
use Config;
use GlpiPlugin\Assetlabel\Format\FormatRegistry;
use Monitor;
use NetworkEquipment;
use Peripheral;
use Phone;
use Printer;

/**
 * Plugin settings, stored in the core `glpi_configs` table (context `plugin:assetlabel`).
 */
final readonly class Settings
{
    private const CONTEXT = 'plugin:assetlabel';

    public const QR_MODE_URL      = 'url';
    public const QR_MODE_TEMPLATE = 'template';
    /**
     * QR code error correction level (L, M, Q or H); not editable in the UI.
     * Higher levels resist damage better but need smaller modules; M suits 300 dpi labels.
     */
    public const QR_ECC           = 'M';
    public const OUTPUTS          = ['pdf' => 'PDF', 'png' => 'PNG'];
    public const EXTRA_TEXT_MAX   = 100;
    public const LENGTH_MIN_MM    = 20;
    public const LENGTH_MAX_MM    = 100;
    public const TEMPLATE_MAX     = 500;

    /**
     * @param list<class-string> $itemtypes         item types labels can be printed for
     * @param string             $format            format id (FormatRegistry)
     * @param int                $continuous_length label length on continuous tape, in mm
     * @param string             $qr_mode           QR_MODE_URL or QR_MODE_TEMPLATE
     * @param string             $qr_template       QR code content template (Placeholders)
     * @param bool               $show_name         print the item name
     * @param bool               $show_otherserial  print the inventory number
     * @param bool               $show_type         print the item type
     * @param bool               $show_entity       print the entity name
     * @param bool               $show_extra        print the additional label
     * @param bool               $show_logo         print the logo
     * @param string             $extra_text        additional label template (Placeholders)
     * @param string             $default_output    default output, a key of OUTPUTS
     * @param bool               $sharp_text        draw text without anti-aliasing
     */
    public function __construct(
        public array $itemtypes,
        public string $format,
        public int $continuous_length,
        public string $qr_mode,
        public string $qr_template,
        public bool $show_name,
        public bool $show_otherserial,
        public bool $show_type,
        public bool $show_entity,
        public bool $show_extra,
        public bool $show_logo,
        public string $extra_text,
        public string $default_output,
        public bool $sharp_text,
    ) {
    }

    /**
     * Settings used on installation and for missing or invalid values.
     *
     * @return self
     */
    public static function defaults(): self
    {
        return new self(
            itemtypes: [
                Computer::class,
                Monitor::class,
                NetworkEquipment::class,
                Peripheral::class,
                Phone::class,
                Printer::class,
            ],
            format: FormatRegistry::DEFAULT,
            continuous_length: 29,
            qr_mode: self::QR_MODE_URL,
            qr_template: '{url}',
            show_name: true,
            show_otherserial: true,
            show_type: true,
            show_entity: false,
            show_extra: true,
            show_logo: true,
            extra_text: '',
            default_output: 'pdf',
            sharp_text: false,
        );
    }

    /**
     * Current settings from the GLPI configuration.
     *
     * @return self
     */
    public static function load(): self
    {
        return self::fromArray(Config::getConfigurationValues(self::CONTEXT));
    }

    /**
     * Builds settings from raw values (stored config or submitted form).
     * Anything missing or invalid falls back to the default value.
     *
     * @param array<string, mixed> $values setting name => raw value
     *
     * @return self
     */
    public static function fromArray(array $values): self
    {
        $defaults = self::defaults();

        $itemtypes = $values['itemtypes'] ?? null;
        if (is_string($itemtypes)) {
            $itemtypes = json_decode($itemtypes, true);
        }
        if (!is_array($itemtypes)) {
            $itemtypes = $defaults->itemtypes;
        }
        $itemtypes = array_values(array_filter($itemtypes, 'is_string'));

        $bool = static fn(string $key): bool
            => is_scalar($values[$key] ?? null) ? (bool) $values[$key] : $defaults->$key;
        $text = static function (string $key, int $max) use ($values, $defaults): string {
            $value = $values[$key] ?? null;
            return is_string($value) ? mb_substr(trim($value), 0, $max) : $defaults->$key;
        };
        $pick = static function (string $key, array $allowed) use ($values, $defaults): string {
            $value = $values[$key] ?? null;
            return in_array($value, $allowed, true) ? $value : $defaults->$key;
        };

        // A custom template without content would make every label fail
        $qr_template = $text('qr_template', self::TEMPLATE_MAX);
        $qr_mode = $qr_template === ''
            ? self::QR_MODE_URL
            : $pick('qr_mode', [self::QR_MODE_URL, self::QR_MODE_TEMPLATE]);

        return new self(
            itemtypes: $itemtypes,
            format: $pick('format', array_keys(FormatRegistry::all())),
            continuous_length: is_numeric($values['continuous_length'] ?? null)
                ? max(self::LENGTH_MIN_MM, min(self::LENGTH_MAX_MM, (int) $values['continuous_length']))
                : $defaults->continuous_length,
            qr_mode: $qr_mode,
            qr_template: $qr_template,
            show_name: $bool('show_name'),
            show_otherserial: $bool('show_otherserial'),
            show_type: $bool('show_type'),
            show_entity: $bool('show_entity'),
            show_extra: $bool('show_extra'),
            show_logo: $bool('show_logo'),
            extra_text: $text('extra_text', self::EXTRA_TEXT_MAX),
            default_output: $pick('default_output', array_keys(self::OUTPUTS)),
            sharp_text: $bool('sharp_text'),
        );
    }

    /**
     * Values as stored in the GLPI configuration (item types JSON-encoded, booleans as 0/1).
     *
     * @return array<string, int|string|false> setting name => stored value
     */
    public function toArray(): array
    {
        return [
            'itemtypes'         => json_encode($this->itemtypes),
            'format'            => $this->format,
            'continuous_length' => $this->continuous_length,
            'qr_mode'           => $this->qr_mode,
            'qr_template'       => $this->qr_template,
            'show_name'         => (int) $this->show_name,
            'show_otherserial'  => (int) $this->show_otherserial,
            'show_type'         => (int) $this->show_type,
            'show_entity'       => (int) $this->show_entity,
            'show_extra'        => (int) $this->show_extra,
            'show_logo'         => (int) $this->show_logo,
            'extra_text'        => $this->extra_text,
            'default_output'    => $this->default_output,
            'sharp_text'        => (int) $this->sharp_text,
        ];
    }

    /**
     * Whether labels can be printed for this item type (enabled in configuration and existing).
     *
     * @param string $itemtype item type class name
     *
     * @return bool
     */
    public function isEnabled(string $itemtype): bool
    {
        return in_array($itemtype, $this->itemtypes, true)
            && is_a($itemtype, CommonDBTM::class, true);
    }

    /**
     * Stores all values in the GLPI configuration.
     *
     * @return void
     */
    public function save(): void
    {
        Config::setConfigurationValues(self::CONTEXT, $this->toArray());
    }

    /**
     * Stores default values for keys that do not exist yet (safe to run on upgrade).
     *
     * @return void
     */
    public static function installDefaults(): void
    {
        $existing = Config::getConfigurationValues(self::CONTEXT);
        $missing  = array_diff_key(self::defaults()->toArray(), $existing);
        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /**
     * Removes all plugin values from the GLPI configuration.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::defaults()->toArray()));
    }
}
