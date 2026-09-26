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

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Assetlabel\Format\FormatRegistry;
use GlpiPlugin\Assetlabel\LabelException;
use GlpiPlugin\Assetlabel\LabelFactory;
use GlpiPlugin\Assetlabel\LabelFile;
use GlpiPlugin\Assetlabel\Logo;
use GlpiPlugin\Assetlabel\Placeholders;
use GlpiPlugin\Assetlabel\Settings;
use Html;
use Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Toolbox;

/**
 * Plugin configuration page (administrators only).
 */
#[SecurityStrategy(Firewall::STRATEGY_ADMIN_ACCESS)]
final class ConfigController extends AbstractController
{
    /**
     * Configuration form with a preview of the sample label.
     * Requires GLPI's global configuration ($CFG_GLPI).
     *
     * @return Response
     */
    #[Route('/config', name: 'config', methods: 'GET')]
    public function show(): Response
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $settings = Settings::load();
        $factory  = new LabelFactory($settings);

        return $this->render('@assetlabel/config.html.twig', [
            'title'            => __('Asset Label', 'assetlabel'),
            'menu'             => ['config', 'plugin'],
            'action'           => Html::getPrefixedUrl('/plugins/assetlabel/config'),
            'preview_url'      => Html::getPrefixedUrl('/plugins/assetlabel/config/preview'),
            'settings'         => $settings,
            'itemtypes'        => LabelFactory::getCandidateItemtypes(),
            'formats'          => FormatRegistry::getNames(),
            'length_min'       => Settings::LENGTH_MIN_MM,
            'length_max'       => Settings::LENGTH_MAX_MM,
            'extra_text_max'   => Settings::EXTRA_TEXT_MAX,
            'outputs'          => Settings::OUTPUTS,
            'placeholders'     => '{' . implode('} {', Placeholders::NAMES) . '}',
            'has_logo'         => Logo::getPath() !== null,
            'format_name'      => $factory->getFormatCaption(),
            'label_width_mm'   => $factory->format->getWidthMm(),
            'missing_url_base' => empty($CFG_GLPI['url_base']),
        ]);
    }

    /**
     * Saves the submitted settings and stores or deletes the logo, then redirects back to the form.
     * Logo errors are shown as a message instead of being thrown.
     *
     * @param Request $request submitted form
     *
     * @return Response
     */
    #[Route('/config', name: 'config_save', methods: 'POST')]
    public function save(Request $request): Response
    {
        $input = $request->request->all();

        // Only keep item types that can actually be labelled
        $itemtypes = array_filter((array) ($input['itemtypes'] ?? []), 'is_string');
        $input['itemtypes'] = array_values(
            array_intersect($itemtypes, array_keys(LabelFactory::getCandidateItemtypes())),
        );
        Settings::fromArray($input)->save();

        try {
            if ($request->request->getBoolean('delete_logo')) {
                Logo::delete();
            }
            $logo = $request->files->get('logo');
            if ($logo instanceof UploadedFile) {
                if (!$logo->isValid()) {
                    throw new LabelException(self::getUploadErrorMessage($logo));
                }
                Logo::store($logo->getPathname());
            }
            Session::addMessageAfterRedirect(__s('Configuration saved.', 'assetlabel'));
        } catch (LabelException $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        }

        return new RedirectResponse(Html::getPrefixedUrl('/plugins/assetlabel/config'));
    }

    /**
     * Sample label with the saved settings. The PDF version draws the safe area frame
     * to check the printer margins.
     *
     * @param Request $request query: `output` (png or pdf), `frame` (draw the safe area frame)
     *
     * @return Response
     *
     * @throws BadRequestHttpException when the output is unknown
     * @throws LabelException when the sample label cannot be rendered
     */
    #[Route('/config/preview', name: 'config_preview', methods: 'GET')]
    public function preview(Request $request): Response
    {
        $output  = $request->query->getString('output', 'png');
        $factory = new LabelFactory(Settings::load());

        $image = $factory->renderData($factory->getDemoData(), $request->query->getBoolean('frame'));

        return match ($output) {
            'png'   => LabelFile::response(LabelFile::png($image), 'label-test.png', true),
            'pdf'   => LabelFile::response(LabelFile::pdf([$image], $factory->format), 'label-test.pdf', true),
            default => throw new BadRequestHttpException(),
        };
    }

    /**
     * Message for a failed logo upload, without the technical details of PHP's upload error.
     *
     * @param UploadedFile $file upload that failed
     *
     * @return string translated message saying what happened and what to do
     */
    private static function getUploadErrorMessage(UploadedFile $file): string
    {
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return sprintf(
                __('The logo file is larger than the server upload limit (%s). Choose a smaller file.', 'assetlabel'),
                Toolbox::getSize(UploadedFile::getMaxFilesize()),
            );
        }
        return __(
            'The logo could not be uploaded. Try again; if it keeps failing, check the PHP upload settings.',
            'assetlabel',
        );
    }
}
