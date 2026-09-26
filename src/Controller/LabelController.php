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
use GlpiPlugin\Assetlabel\LabelFactory;
use GlpiPlugin\Assetlabel\LabelFile;
use GlpiPlugin\Assetlabel\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Label of a single item, returned directly (PDF shown inline, PNG downloaded).
 */
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
     * @throws \GlpiPlugin\Assetlabel\LabelException when the label cannot be rendered
     */
    #[Route('/label', name: 'label', methods: 'GET')]
    #[SecurityStrategy(Firewall::STRATEGY_CENTRAL_ACCESS)]
    public function __invoke(Request $request): Response
    {
        $settings = Settings::load();
        $itemtype = $request->query->getString('itemtype');
        $id       = $request->query->getInt('id');
        $output   = $request->query->getString('output', 'pdf');
        $preview  = $request->query->getBoolean('preview');

        if (!array_key_exists($output, Settings::OUTPUTS)) {
            throw new BadRequestHttpException();
        }
        if ($id <= 0 || !$settings->isEnabled($itemtype)) {
            throw new NotFoundHttpException();
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->can($id, READ)) {
            throw new AccessDeniedHttpException();
        }

        $factory = new LabelFactory($settings);
        $image   = $factory->render($item);

        $filename = LabelFactory::getFileBaseName($item) . '.' . $output;
        if ($output === 'pdf') {
            return LabelFile::response(LabelFile::pdf([$image], $factory->format), $filename, true);
        }
        return LabelFile::response(LabelFile::png($image), $filename, $preview);
    }
}
