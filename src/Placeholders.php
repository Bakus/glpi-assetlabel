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

/**
 * Fills `{placeholder}` templates (QR code content, additional label) with item values,
 * e.g. `{otherserial};{serial}`.
 */
final class Placeholders
{
    public const NAMES = ['id', 'name', 'otherserial', 'serial', 'itemtype', 'type', 'entity', 'url'];

    /**
     * Replaces every known placeholder in the template (missing values give empty strings)
     * and trims the result. Unknown placeholders are left as they are.
     *
     * @param string                $template text with `{placeholder}` markers
     * @param array<string, string> $values placeholder name => value
     *
     * @return string
     */
    public static function replace(string $template, array $values): string
    {
        $replacements = [];
        foreach (self::NAMES as $placeholder) {
            $replacements['{' . $placeholder . '}'] = $values[$placeholder] ?? '';
        }
        return trim(strtr($template, $replacements));
    }
}
