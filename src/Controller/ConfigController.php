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
use GlpiPlugin\Assetlabel\LabelPrinter;
use GlpiPlugin\Assetlabel\Logo;
use GlpiPlugin\Assetlabel\Placeholders;
use GlpiPlugin\Assetlabel\Settings;
use Html;
use Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
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
            'printer_url'      => Html::getPrefixedUrl('/plugins/assetlabel/config/printer'),
            'settings'         => $settings,
            'itemtypes'        => LabelFactory::getCandidateItemtypes(),
            'formats'          => FormatRegistry::getNames(),
            'length_min'       => Settings::LENGTH_MIN_MM,
            'length_max'       => Settings::LENGTH_MAX_MM,
            'extra_text_max'   => Settings::EXTRA_TEXT_MAX,
            'outputs'          => $settings->getOutputs(),
            'requirements'     => self::getPrinterRequirements(),
            'host_max'         => Settings::HOST_MAX,
            'cuts'             => self::getCutNames(),
            'qualities'        => self::getQualityNames(),
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
        $settings = Settings::fromArray($input);
        $settings->save();

        // The printer choice needs a valid address; it is dropped silently otherwise
        $host = trim($request->request->getString('printer_host'));
        if ($host !== $settings->printer_host) {
            Session::addMessageAfterRedirect(
                __s('The printer address is not a valid IP address or host name; it has not been saved.', 'assetlabel'),
                false,
                WARNING,
            );
        } elseif ($request->request->getBoolean('printer_enabled') && !$settings->printer_enabled) {
            Session::addMessageAfterRedirect(
                __s('Direct printing needs the printer address, so it has not been enabled.', 'assetlabel'),
                false,
                WARNING,
            );
        }

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
     * Connection test for the printer address typed in the form (it does not have to be saved):
     * printer model, state and loaded paper, with the matching label type.
     *
     * @param Request $request form: `host`
     *
     * @return JsonResponse `{ok: true, rows: [[label, value], ...], media: string, format: ?string,
     *                      warning: ?string, supported: {printer_cut: list<string>, printer_quality: list<string>}}`
     *                      or `{ok: false, message: string}`
     */
    #[Route('/config/printer', name: 'config_printer', methods: 'POST')]
    public function printer(Request $request): JsonResponse
    {
        $host = trim($request->request->getString('host'));
        if ($host === '' || !Settings::isValidHost($host)) {
            return new JsonResponse([
                'ok'      => false,
                'message' => __('Enter a valid IP address or host name.', 'assetlabel'),
            ]);
        }

        try {
            $status = (new LabelPrinter($host))->getStatus();
        } catch (LabelException $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()]);
        }

        $dpi         = (new LabelFactory(Settings::load()))->format->getDpi();
        $resolutions = $status->getResolutions();
        $format      = $status->findFormatId();
        $warning     = null;
        if (!$status->supports_urf) {
            $warning = __(
                'The printer does not accept AirPrint raster images (image/urf), direct printing will not work.',
                'assetlabel',
            );
        } elseif (!$status->supportsGray8()) {
            $warning = __(
                'The printer does not accept 8-bit grayscale AirPrint images (W8), direct printing will not work.',
                'assetlabel',
            );
        } elseif (!in_array($dpi, $resolutions, true)) {
            $warning = sprintf(
                __(
                    'The printer does not print AirPrint images at %d dpi, direct printing will not work.',
                    'assetlabel',
                ),
                $dpi,
            );
        } elseif ($format === null) {
            $warning = __('No label type of the plugin matches the loaded paper.', 'assetlabel');
        }

        return new JsonResponse([
            'ok'        => true,
            'rows'      => [
                [__('Printer', 'assetlabel'), $status->model],
                [__('State', 'assetlabel'), $status->getStateDescription()],
                [__('Loaded paper', 'assetlabel'), $status->getMediaDescription()],
                [__('AirPrint raster (image/urf)', 'assetlabel'), $status->supports_urf ? __('Yes') : __('No')],
                [__('8-bit grayscale (W8)', 'assetlabel'), $status->supportsGray8() ? __('Yes') : __('No')],
                [
                    __('Resolution', 'assetlabel'),
                    $resolutions === [] ? __('unknown', 'assetlabel') : implode(', ', $resolutions) . ' dpi',
                ],
                [
                    __('Cutting', 'assetlabel'),
                    self::getSupportedNames(self::getCutNames(), LabelPrinter::FINISHINGS, $status->finishings),
                ],
                [
                    __('Print quality', 'assetlabel'),
                    self::getSupportedNames(self::getQualityNames(), LabelPrinter::QUALITIES, $status->qualities),
                ],
            ],
            'media'     => $status->getMediaDescription(),
            'format'    => $format,
            'warning'   => $warning,
            // Setting values the printer supports, to mark the others in the form
            'supported' => [
                'printer_cut'     => array_keys(array_intersect(LabelPrinter::FINISHINGS, $status->finishings)),
                'printer_quality' => array_keys(array_intersect(LabelPrinter::QUALITIES, $status->qualities)),
            ],
        ]);
    }

    /**
     * Names of the cut settings, for the form and the connection test.
     *
     * @return array<string, string> setting value (Settings::CUTS) => translated name
     */
    private static function getCutNames(): array
    {
        return [
            'label' => __('After each label', 'assetlabel'),
            'job'   => __('Once, after the last label', 'assetlabel'),
            'none'  => __('Do not cut', 'assetlabel'),
        ];
    }

    /**
     * Names of the print quality settings, for the form and the connection test.
     *
     * @return array<string, string> setting value (Settings::QUALITIES) => translated name
     */
    private static function getQualityNames(): array
    {
        return [
            'normal' => __('Normal', 'assetlabel'),
            'high'   => __('High (slower)', 'assetlabel'),
        ];
    }

    /**
     * Names of the setting values the printer supports, one per line, for the connection test.
     *
     * @param array<string, string> $names     setting value => translated name
     * @param array<string, int>    $ipp       setting value => IPP enum value
     * @param list<int>             $supported IPP enum values supported by the printer
     *
     * @return string names separated by line breaks, or "not supported"
     */
    private static function getSupportedNames(array $names, array $ipp, array $supported): string
    {
        $available = array_filter(
            $names,
            static fn(string $value): bool => in_array($ipp[$value], $supported, true),
            ARRAY_FILTER_USE_KEY,
        );
        return $available === [] ? __('not supported', 'assetlabel') : implode("\n", $available);
    }

    /**
     * What direct printing needs on the GLPI server, and whether it is available.
     *
     * @return list<array{label: string, ok: bool}>
     */
    private static function getPrinterRequirements(): array
    {
        return [
            [
                'label' => __('PHP gd extension with FreeType (renders the labels)', 'assetlabel'),
                'ok'    => function_exists('imagettftext'),
            ],
            [
                'label' => __('PHP curl extension or allow_url_fopen (IPP requests over HTTP)', 'assetlabel'),
                'ok'    => extension_loaded('curl') || (bool) ini_get('allow_url_fopen'),
            ],
        ];
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
