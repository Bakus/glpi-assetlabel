<?php

/**
 * Asset Label - QR code inventory labels for GLPI on Brother QL printers.
 *
 * @author  Krzysztof Andrzej Błachut vel Bakus
 * @license 0BSD
 * @link    https://github.com/Bakus/glpi-assetlabel
 */

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Assetlabel\LabelTab;
use GlpiPlugin\Assetlabel\Settings;

const PLUGIN_ASSETLABEL_VERSION = '0.3.0';

/**
 * Plugin metadata and requirements read by GLPI's plugin manager.
 *
 * @return array{
 *     name: string,
 *     version: string,
 *     author: string,
 *     license: string,
 *     homepage: string,
 *     requirements: array<string, array<string, mixed>>
 * }
 */
function plugin_version_assetlabel(): array
{
    return [
        'name'         => 'Asset Label',
        'version'      => PLUGIN_ASSETLABEL_VERSION,
        'author'       => 'Krzysztof Andrzej Błachut vel Bakus',
        'license'      => '0BSD',
        'homepage'     => 'https://github.com/Bakus/glpi-assetlabel',
        'requirements' => [
            // Upper bounds are exclusive.
            'glpi' => ['min' => '12.0.0', 'max' => '12.1.0'],
            'php'  => [
                'min'  => '8.3',
                'max'  => '8.6',
                'exts' => [
                    'gd'  => ['required' => true, 'function' => 'imagettftext'],
                    'zip' => ['required' => true, 'class' => 'ZipArchive'],
                ],
            ],
        ],
    ];
}

/**
 * Registers the plugin hooks: configuration page, massive action and the "Label" tab
 * on every item type enabled in the settings.
 *
 * @return void
 */
function plugin_init_assetlabel(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['assetlabel'] = 'config';
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['assetlabel'] = true;

    // Only strings are stored here, so custom asset classes (booted after plugins) are fine.
    foreach (Settings::load()->itemtypes as $itemtype) {
        CommonGLPI::registerStandardTab($itemtype, LabelTab::class);
    }
}
