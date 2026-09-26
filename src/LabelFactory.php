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

use Cable;
use CommonDBTM;
use Computer;
use Enclosure;
use Entity;
use GdImage;
use GlpiPlugin\Assetlabel\Format\ContinuousTape;
use GlpiPlugin\Assetlabel\Format\FormatRegistry;
use GlpiPlugin\Assetlabel\Format\LabelFormat;
use PassiveDCEquipment;
use PDU;
use Rack;

/**
 * Glue between GLPI items and the label renderer.
 */
final class LabelFactory
{
    public readonly LabelFormat $format;

    /**
     * Resolves the configured format; continuous tape gets the configured length.
     *
     * @param Settings $settings plugin settings
     */
    public function __construct(private readonly Settings $settings)
    {
        $format = FormatRegistry::get($settings->format);
        $this->format = $format instanceof ContinuousTape ? $format->withLength($settings->continuous_length) : $format;
    }

    /**
     * Format name shown under previews; includes the length for continuous tape.
     *
     * @return string
     */
    public function getFormatCaption(): string
    {
        if ($this->format instanceof ContinuousTape) {
            return sprintf(
                __('%1$s, %2$d mm long', 'assetlabel'),
                $this->format->getName(),
                round($this->format->getLengthMm()),
            );
        }
        return $this->format->getName();
    }

    /**
     * Item types that can be enabled in the configuration (includes custom assets).
     * Requires GLPI's global configuration ($CFG_GLPI).
     *
     * @return array<class-string<CommonDBTM>, string> class => translated name
     */
    public static function getCandidateItemtypes(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $candidates = [];
        $classes = array_merge(
            $CFG_GLPI['asset_types'],
            [Rack::class, Enclosure::class, PDU::class, PassiveDCEquipment::class, Cable::class],
        );
        foreach (array_unique($classes) as $class) {
            if (is_a($class, CommonDBTM::class, true)) {
                $candidates[$class] = $class::getTypeName(1);
            }
        }
        asort($candidates);
        return $candidates;
    }

    /**
     * Renders the label of an item. The caller is responsible for the rights check.
     *
     * @param CommonDBTM $item item loaded from the database
     *
     * @return GdImage
     *
     * @throws LabelException when the QR code content is empty or too long for the label
     */
    public function render(CommonDBTM $item): GdImage
    {
        return $this->renderData($this->getData($item));
    }

    /**
     * Renders label data with the configured format and text settings.
     *
     * @param LabelData $data           content of the label
     * @param bool      $show_safe_area draw a gray frame around the content area (margin check)
     *
     * @return GdImage
     *
     * @throws LabelException when the QR code content is empty or too long for the label
     */
    public function renderData(LabelData $data, bool $show_safe_area = false): GdImage
    {
        return (new LabelRenderer($this->format, Settings::QR_ECC, $this->settings->sharp_text))
            ->render($data, $show_safe_area);
    }

    /**
     * Label content of an item, according to the settings.
     * Requires GLPI's global configuration ($CFG_GLPI) for the item URL.
     *
     * @param CommonDBTM $item item loaded from the database
     *
     * @return LabelData
     */
    public function getData(CommonDBTM $item): LabelData
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $fields = [
            'id'          => (string) $item->getID(),
            'name'        => (string) ($item->fields['name'] ?? ''),
            'otherserial' => (string) ($item->fields['otherserial'] ?? ''),
            'serial'      => (string) ($item->fields['serial'] ?? ''),
            'itemtype'    => $item::class,
            'type'        => $item::getTypeName(1),
            'entity'      => $this->getEntityName((int) ($item->fields['entities_id'] ?? 0)),
            'url'         => $CFG_GLPI['url_base'] . $item::getFormURLWithID($item->getID(), false),
        ];

        return $this->buildData($fields);
    }

    /**
     * Sample label used for the configuration preview and the printer test.
     *
     * @return LabelData
     */
    public function getDemoData(): LabelData
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return $this->buildData([
            'id'          => '123',
            'name'        => 'PC-OFFICE-01',
            'otherserial' => 'INV-2026-00123',
            'serial'      => 'SN1234567890',
            'itemtype'    => 'Computer',
            'type'        => Computer::getTypeName(1),
            'entity'      => $this->getEntityName(0),
            'url'         => $CFG_GLPI['url_base'] . '/front/computer.form.php?id=123',
        ]);
    }

    /**
     * Applies the settings to item values: QR code content and the lines to print.
     *
     * @param array<string, string> $fields values for the QR template placeholders
     *
     * @return LabelData
     */
    private function buildData(array $fields): LabelData
    {
        $settings = $this->settings;

        $payload = $settings->qr_mode === Settings::QR_MODE_TEMPLATE
            ? Placeholders::replace($settings->qr_template, $fields)
            : $fields['url'];

        return new LabelData(
            qr_payload: $payload,
            name: $settings->show_name ? $fields['name'] : '',
            otherserial: $settings->show_otherserial ? $fields['otherserial'] : '',
            type: $settings->show_type ? $fields['type'] : '',
            entity: $settings->show_entity ? $fields['entity'] : '',
            extra: $settings->show_extra ? Placeholders::replace($settings->extra_text, $fields) : '',
            logo_path: $settings->show_logo ? Logo::getPath() : null,
        );
    }

    /**
     * A file name that is unique within a batch and safe in ZIP archives.
     *
     * @param CommonDBTM $item item loaded from the database
     *
     * @return string file name without extension, e.g. `computer_123_PC-01`
     */
    public static function getFileBaseName(CommonDBTM $item): string
    {
        $name = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', (string) ($item->fields['name'] ?? ''));
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.');
        return sprintf(
            '%s_%d%s',
            strtolower(basename(str_replace('\\', '/', $item::class))),
            $item->getID(),
            $name !== '' ? '_' . $name : '',
        );
    }

    /**
     * Name of an entity.
     *
     * @param int $entities_id entity ID
     *
     * @return string empty when the entity does not exist
     */
    private function getEntityName(int $entities_id): string
    {
        $entity = new Entity();
        return $entity->getFromDB($entities_id) ? (string) $entity->fields['name'] : '';
    }
}
