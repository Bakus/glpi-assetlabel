<?php

/**
 * Asset Label - QR code inventory labels for GLPI on Brother QL printers.
 *
 * @author  Krzysztof Andrzej Błachut vel Bakus
 * @license 0BSD
 * @link    https://github.com/Bakus/glpi-assetlabel
 */

declare(strict_types=1);

use GlpiPlugin\Assetlabel\LabelMassiveAction;
use GlpiPlugin\Assetlabel\Logo;
use GlpiPlugin\Assetlabel\Settings;

/**
 * Stores the default settings missing from the configuration and creates the logo directory.
 * Safe to run again on upgrade.
 *
 * @return bool always true
 */
function plugin_assetlabel_install(): bool
{
    Settings::installDefaults();
    Logo::ensureDirectory();
    return true;
}

/**
 * Removes the plugin settings and the stored logo.
 *
 * @return bool always true
 */
function plugin_assetlabel_uninstall(): bool
{
    Settings::uninstall();
    Logo::delete();
    return true;
}

/**
 * Adds the "Print labels" massive action to the lists of item types enabled in the settings.
 *
 * @param string $itemtype item type of the list
 *
 * @return array<string, string> action key => HTML label (empty for other item types)
 */
function plugin_assetlabel_MassiveActions(string $itemtype): array
{
    if (!Settings::load()->isEnabled($itemtype)) {
        return [];
    }

    $key = LabelMassiveAction::class . MassiveAction::CLASS_ACTION_SEPARATOR . LabelMassiveAction::ACTION;
    return [$key => '<i class="ti ti-qrcode"></i> ' . __s('Print labels', 'assetlabel')];
}
