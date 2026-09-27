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
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Html;

/**
 * "Label" tab on asset forms: preview and download of the item's label and, with direct printing,
 * a print button.
 */
final class LabelTab extends CommonGLPI
{
    /**
     * Translated name of the tab.
     *
     * @param int $nb number of items (singular or plural form)
     *
     * @return string
     */
    public static function getTypeName($nb = 0)
    {
        return _n('Label', 'Labels', $nb, 'assetlabel');
    }

    /**
     * Tabler icon class of the tab.
     *
     * @return string
     */
    public static function getIcon()
    {
        return 'ti ti-qrcode';
    }

    /**
     * Tab title, shown only for saved items of enabled types that the user can read.
     *
     * @param CommonGLPI $item         item whose form is displayed
     * @param int        $withtemplate template mode (no tab on templates)
     *
     * @return string tab HTML, empty when the tab is hidden
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !self::canShowFor($item, Settings::load())) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(1), 0, $item::class, self::getIcon());
    }

    /**
     * Displays the label preview, the download form of the item and, with direct printing,
     * the print button.
     *
     * @param CommonGLPI $item         item whose form is displayed
     * @param int        $tabnum       tab number (unused)
     * @param int        $withtemplate template mode (unused)
     *
     * @return bool false when the tab is not available for the item
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $settings = Settings::load();
        if (!self::canShowFor($item, $settings)) {
            return false;
        }
        $factory = new LabelFactory($settings);

        $url = Html::getPrefixedUrl('/plugins/assetlabel/label');
        $query = ['itemtype' => $item::class, 'id' => $item->getID()];

        TemplateRenderer::getInstance()->display('@assetlabel/tab.html.twig', [
            'action'         => $url,
            'query'          => $query,
            'preview_url'    => $url . '?' . http_build_query($query + ['output' => 'png', 'preview' => 1]),
            'outputs'        => Settings::OUTPUTS,
            'default_output' => $settings->default_output,
            'print_url'      => $settings->printer_enabled ? Html::getPrefixedUrl('/plugins/assetlabel/print') : null,
            'format_name'    => $factory->getFormatCaption(),
            'label_width_mm' => $factory->format->getWidthMm(),
        ]);
        return true;
    }

    /**
     * Whether the item is a saved item of an enabled type that the user can read.
     *
     * @param CommonGLPI $item     item whose form is displayed
     * @param Settings   $settings plugin settings
     *
     * @return bool
     */
    private static function canShowFor(CommonGLPI $item, Settings $settings): bool
    {
        return $item instanceof CommonDBTM
            && !$item->isNewItem()
            && $settings->isEnabled($item::class)
            && $item->can($item->getID(), READ);
    }
}
