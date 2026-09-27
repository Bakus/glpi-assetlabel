<?php

/**
 * Asset Label - QR code inventory labels for GLPI on Brother QL printers.
 *
 * @author  Krzysztof Andrzej Błachut vel Bakus
 * @license 0BSD
 * @link    https://github.com/Bakus/glpi-assetlabel
 */

declare(strict_types=1);

namespace GlpiPlugin\Assetlabel\Controller;

use CommonDBTM;
use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Assetlabel\LabelException;
use GlpiPlugin\Assetlabel\LabelFactory;
use GlpiPlugin\Assetlabel\LabelFile;
use GlpiPlugin\Assetlabel\LabelPrinter;
use GlpiPlugin\Assetlabel\Settings;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Label of a single item: returned directly (PDF shown inline, PNG downloaded) or sent to the printer.
 */
#[SecurityStrategy(Firewall::STRATEGY_CENTRAL_ACCESS)]
final class LabelController extends AbstractController
{
    /**
     * Label of the item given in the query, after checking that its type is enabled
     * and that the user can read it.
     *
     * @param Request $request query: `itemtype`, `id`, `output` (pdf or png), `preview` (show the PNG inline)
     *
     * @return Response
     *
     * @throws BadRequestHttpException when the output is unknown
     * @throws NotFoundHttpException when the ID is invalid or the item type is not enabled
     * @throws AccessDeniedHttpException when the user cannot read the item
     * @throws LabelException when the label cannot be rendered
     */
    #[Route('/label', name: 'label', methods: 'GET')]
    public function download(Request $request): Response
    {
        $settings = Settings::load();
        $output   = $request->query->getString('output', 'pdf');
        $preview  = $request->query->getBoolean('preview');

        if (!array_key_exists($output, Settings::OUTPUTS)) {
            throw new BadRequestHttpException();
        }
        $item = self::getItem($settings, $request->query->getString('itemtype'), $request->query->getInt('id'));

        $factory = new LabelFactory($settings);
        $image   = $factory->render($item);

        $filename = LabelFactory::getFileBaseName($item) . '.' . $output;
        if ($output === 'pdf') {
            return LabelFile::response(LabelFile::pdf([$image], $factory->format), $filename, true);
        }
        return LabelFile::response(LabelFile::png($image), $filename, $preview);
    }

    /**
     * Sends the label of the item to the printer, then goes back to the item with a message
     * saying whether it worked.
     *
     * @param Request $request form: `itemtype`, `id`
     *
     * @return Response
     *
     * @throws NotFoundHttpException when direct printing is off, the ID is invalid or the item type
     *                               is not enabled
     * @throws AccessDeniedHttpException when the user cannot read the item
     */
    #[Route('/print', name: 'print', methods: 'POST')]
    public function print(Request $request): Response
    {
        $settings = Settings::load();
        if (!$settings->printer_enabled) {
            throw new NotFoundHttpException();
        }
        $item = self::getItem($settings, $request->request->getString('itemtype'), $request->request->getInt('id'));

        try {
            $factory = new LabelFactory($settings);
            $name    = LabelFactory::getFileBaseName($item);
            LabelPrinter::fromSettings($settings)
                ->printLabels([$factory->render($item)], $factory->format, $settings->format, $name);
            Session::addMessageAfterRedirect(__s('The label has been sent to the printer.', 'assetlabel'));
        } catch (LabelException $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        }

        return new RedirectResponse($item::getFormURLWithID($item->getID()));
    }

    /**
     * Loads an item after checking that its type is enabled and that the user can read it.
     *
     * @param Settings $settings plugin settings
     * @param string   $itemtype item type class name
     * @param int      $id       item ID
     *
     * @return CommonDBTM item loaded from the database
     *
     * @throws NotFoundHttpException when the ID is invalid or the item type is not enabled
     * @throws AccessDeniedHttpException when the user cannot read the item
     */
    private static function getItem(Settings $settings, string $itemtype, int $id): CommonDBTM
    {
        if ($id <= 0 || !$settings->isEnabled($itemtype)) {
            throw new NotFoundHttpException();
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->can($id, READ)) {
            throw new AccessDeniedHttpException();
        }
        return $item;
    }
}
