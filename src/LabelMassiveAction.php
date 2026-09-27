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
use Glpi\Application\View\TemplateRenderer;
use Html;
use MassiveAction;

/**
 * "Print labels" bulk action: one PDF (one label per page), a ZIP of PNG files, or one print job
 * sent directly to the printer.
 */
final class LabelMassiveAction
{
    public const ACTION = 'print';

    /**
     * Displays the output choice (PDF, PNG or printer) of the massive action form.
     *
     * @param MassiveAction $ma current massive action
     *
     * @return bool always true
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        $settings = Settings::load();
        TemplateRenderer::getInstance()->display('@assetlabel/massive_action.html.twig', [
            'outputs'        => $settings->getOutputs(),
            'default_output' => $settings->default_output,
        ]);
        return true;
    }

    /**
     * GLPI calls this once per selected item type, and may restart the request (progress bar
     * refresh) inside `itemDone()`. So the first call generates one file for the whole selection
     * and stores the per-item results in the session; each call then only reports the results
     * of its item type, removing them from the session before reporting.
     *
     * @param MassiveAction     $ma   current massive action
     * @param CommonDBTM        $item empty instance of the processed item type
     * @param array<int, mixed> $ids  selected items of this type, keyed by ID
     *
     * @return void
     *
     * @throws LabelException when the ZIP archive cannot be created
     */
    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids): void
    {
        $key = 'plugin_assetlabel_ma_' . md5(serialize([$ma->getItems(), $ma->getInput()]));
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = self::generate($ma);
        }

        if ($_SESSION[$key]['redirect'] !== null) {
            $ma->setRedirect($_SESSION[$key]['redirect']);
        }
        foreach ($_SESSION[$key]['messages'] as $message) {
            $ma->addMessage($message);
        }
        $_SESSION[$key]['messages'] = [];

        $results = $_SESSION[$key]['results'][$item::class] ?? [];
        unset($_SESSION[$key]['results'][$item::class]);
        if ($_SESSION[$key]['results'] === []) {
            unset($_SESSION[$key]);
        }

        foreach ($results as $status => $done_ids) {
            $ma->itemDone($item::class, $done_ids, $status);
        }
    }

    /**
     * Renders the labels of all selected items into one file and stores it as a Batch, or sends
     * them to the printer as one job. Items of disabled types, items without READ right and
     * items whose label fails are reported as failed; when the print job fails, all items are.
     * Requires an active session.
     *
     * @param MassiveAction $ma current massive action
     *
     * @return array{redirect: ?string, messages: list<string>, results: array<string, array<int, list<int>>>}
     *
     * @throws LabelException when the ZIP archive cannot be created
     */
    private static function generate(MassiveAction $ma): array
    {
        $settings = Settings::load();
        $factory  = new LabelFactory($settings);
        $output   = $ma->getInput()['output'] ?? 'pdf';
        $valid_output = is_string($output) && array_key_exists($output, $settings->getOutputs());

        $images   = [];
        $messages = [];
        $results  = [];
        foreach ($ma->getItems() as $itemtype => $ids) {
            // Only instantiate item types that are enabled
            $item = $valid_output && $settings->isEnabled($itemtype) ? getItemForItemtype($itemtype) : false;
            foreach (array_keys($ids) as $id) {
                if (!$item instanceof CommonDBTM) {
                    $status = MassiveAction::ACTION_KO;
                } elseif (!$item->can($id, READ)) {
                    $status = MassiveAction::ACTION_NORIGHT;
                } else {
                    try {
                        $images[LabelFactory::getFileBaseName($item)] = $factory->render($item);
                        $status = MassiveAction::ACTION_OK;
                    } catch (LabelException $e) {
                        $status = MassiveAction::ACTION_KO;
                        $messages[] = htmlescape(sprintf('%s: %s', $item->getNameID(), $e->getMessage()));
                    }
                }
                $results[$itemtype][$status][] = $id;
            }
        }

        $redirect = null;
        if ($images !== [] && $output === Settings::OUTPUT_PRINTER) {
            try {
                LabelPrinter::fromSettings($settings)
                    ->printLabels(array_values($images), $factory->format, $settings->format, 'labels');
                $count = count($images);
                $messages[] = htmlescape(sprintf(
                    _n(
                        '%d label has been sent to the printer.',
                        '%d labels have been sent to the printer.',
                        $count,
                        'assetlabel',
                    ),
                    $count,
                ));
            } catch (LabelException $e) {
                $messages[] = htmlescape($e->getMessage());
                foreach ($results as $itemtype => $statuses) {
                    $results[$itemtype][MassiveAction::ACTION_KO] = array_merge(
                        $statuses[MassiveAction::ACTION_KO] ?? [],
                        $statuses[MassiveAction::ACTION_OK] ?? [],
                    );
                    unset($results[$itemtype][MassiveAction::ACTION_OK]);
                }
            }
        } elseif ($images !== []) {
            if ($output === 'pdf') {
                $content  = LabelFile::pdf(array_values($images), $factory->format);
                $filename = 'labels.pdf';
            } elseif (count($images) === 1) {
                $content  = LabelFile::png(reset($images));
                $filename = array_key_first($images) . '.png';
            } else {
                $content  = LabelFile::zip($images);
                $filename = 'labels.zip';
            }
            $batch = Batch::create($content, $filename, (string) Html::getBackUrl());
            $redirect = Html::getPrefixedUrl('/plugins/assetlabel/batch/' . $batch->token);
        }

        return ['redirect' => $redirect, 'messages' => $messages, 'results' => $results];
    }
}
