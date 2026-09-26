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

use ReflectionClass;

/**
 * Available label formats: every instantiable LabelFormat class in this directory.
 * Adding a format only requires adding a file here.
 */
final class FormatRegistry
{
    public const DEFAULT = 'Dk11209';

    /**
     * Instantiates every non-abstract LabelFormat class found in this directory.
     *
     * @return array<string, LabelFormat> format id (class short name) => format, sorted by name
     */
    public static function all(): array
    {
        $formats = [];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            $id = basename($file, '.php');
            $class = __NAMESPACE__ . '\\' . $id;
            if (is_a($class, LabelFormat::class, true) && (new ReflectionClass($class))->isInstantiable()) {
                $formats[$id] = new $class();
            }
        }

        uasort($formats, static fn(LabelFormat $a, LabelFormat $b) => strnatcasecmp($a->getName(), $b->getName()));
        return $formats;
    }

    /**
     * Returns the format, or the default one if it does not exist (e.g. file removed).
     *
     * @param string $id format id (class short name)
     *
     * @return LabelFormat
     */
    public static function get(string $id): LabelFormat
    {
        $formats = self::all();
        return $formats[$id] ?? $formats[self::DEFAULT];
    }

    /**
     * Names of all formats, for the configuration form.
     *
     * @return array<string, string> format id => display name
     */
    public static function getNames(): array
    {
        return array_map(static fn(LabelFormat $format) => $format->getName(), self::all());
    }
}
