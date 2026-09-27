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

use GdImage;
use GlpiPlugin\Assetlabel\Format\LabelFormat;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Direct printing over IPP (driverless / AirPrint) on port 631 of a network printer.
 */
final class LabelPrinter
{
    private const PORT            = 631;
    private const PATH            = '/ipp/print';
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT         = 60;

    /**
     * IPP "finishings" value of each cut setting (Settings::CUTS): trim-after-pages,
     * trim-after-job and none.
     */
    public const FINISHINGS = ['label' => 60, 'job' => 63, 'none' => 3];

    /**
     * IPP "print-quality" value of each quality setting (Settings::QUALITIES).
     */
    public const QUALITIES = ['normal' => 4, 'high' => 5];

    /**
     * @param string $host    IP address or host name of the printer
     * @param string $cut     when the printer cuts, a key of FINISHINGS
     * @param string $quality print quality, a key of QUALITIES
     */
    public function __construct(
        private readonly string $host,
        private readonly string $cut = 'label',
        private readonly string $quality = 'normal',
    ) {
    }

    /**
     * Printer configured in the settings, with its cut and quality settings.
     *
     * @param Settings $settings plugin settings
     *
     * @return self
     */
    public static function fromSettings(Settings $settings): self
    {
        return new self($settings->printer_host, $settings->printer_cut, $settings->printer_quality);
    }

    /**
     * Asks the printer for its state and the loaded paper.
     *
     * @return PrinterStatus
     *
     * @throws LabelException when the printer cannot be reached or does not answer correctly
     */
    public function getStatus(): PrinterStatus
    {
        $request = Ipp::encode(Ipp::OPERATION_GET_PRINTER_ATTRIBUTES, 1, [
            'printer-uri'          => [Ipp::URI, $this->getUri('ipp')],
            'requested-attributes' => [Ipp::KEYWORD, PrinterStatus::ATTRIBUTES],
        ]);
        return PrinterStatus::fromAttributes($this->send($request));
    }

    /**
     * Sends the labels as one print job, after checking that the printer accepts 8-bit grayscale
     * AirPrint raster at the format's resolution, is not stopped, and has the paper of the
     * configured format loaded. Cutting and quality are only asked for when the printer
     * supports them; otherwise the printer's defaults apply.
     *
     * @param list<GdImage> $images    label bitmaps, one per label
     * @param LabelFormat   $format    format the images were rendered for
     * @param string        $format_id id of that format (FormatRegistry)
     * @param string        $job_name  job name shown by the printer
     *
     * @return int job number given by the printer (0 when not reported)
     *
     * @throws LabelException when the printer is not ready for these labels or rejects the job
     */
    public function printLabels(array $images, LabelFormat $format, string $format_id, string $job_name): int
    {
        $status = $this->getStatus();
        if (!$status->supports_urf) {
            throw new LabelException(sprintf(
                __('The printer %s does not accept AirPrint raster images (image/urf).', 'assetlabel'),
                $status->model !== '' ? $status->model : $this->host,
            ));
        }
        if (!$status->supportsGray8()) {
            throw new LabelException(sprintf(
                __('The printer %s does not accept 8-bit grayscale AirPrint images (W8).', 'assetlabel'),
                $status->model !== '' ? $status->model : $this->host,
            ));
        }
        if (!in_array($format->getDpi(), $status->getResolutions(), true)) {
            throw new LabelException(sprintf(
                __('The printer %1$s does not print AirPrint images at %2$d dpi.', 'assetlabel'),
                $status->model !== '' ? $status->model : $this->host,
                $format->getDpi(),
            ));
        }
        if ($status->state === PrinterStatus::STATE_STOPPED) {
            throw new LabelException(sprintf(
                __('The printer is not ready: %s.', 'assetlabel'),
                $status->getStateDescription(),
            ));
        }
        if ($status->findFormatId() !== $format_id) {
            throw new LabelException(sprintf(
                __('The loaded paper (%1$s) does not match the label type in the configuration (%2$s).', 'assetlabel'),
                $status->getMediaDescription(),
                $format->getName(),
            ));
        }

        $job_attributes = $status->media !== '' ? ['media' => [Ipp::KEYWORD, $status->media]] : [];
        $job_attributes['print-scaling'] = [Ipp::KEYWORD, 'none'];
        $finishing = self::FINISHINGS[$this->cut] ?? null;
        if (in_array($finishing, $status->finishings, true)) {
            $job_attributes['finishings'] = [Ipp::ENUM, $finishing];
        }
        $quality = self::QUALITIES[$this->quality] ?? null;
        if (in_array($quality, $status->qualities, true)) {
            $job_attributes['print-quality'] = [Ipp::ENUM, $quality];
        }
        $request = Ipp::encode(Ipp::OPERATION_PRINT_JOB, 2, [
            'printer-uri'          => [Ipp::URI, $this->getUri('ipp')],
            'requesting-user-name' => [Ipp::NAME, 'GLPI'],
            'job-name'             => [Ipp::NAME, $job_name],
            'document-format'      => [Ipp::MIME_MEDIA_TYPE, 'image/urf'],
        ], $job_attributes);

        $attributes = $this->send($request . LabelFile::urf($images, $format));
        return (int) ($attributes['job-id'][0] ?? 0);
    }

    /**
     * Printer URI; IPv6 addresses are put in brackets.
     *
     * @param string $scheme ipp (inside IPP requests) or http (to connect)
     *
     * @return string
     */
    private function getUri(string $scheme): string
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $this->host . ']'
            : $this->host;
        return sprintf('%s://%s:%d%s', $scheme, $host, self::PORT, self::PATH);
    }

    /**
     * Sends an IPP request over HTTP and checks the IPP status of the answer.
     *
     * @param string $request encoded request, followed by the document data if any
     *
     * @return array<string, list<mixed>> decoded response attributes
     *
     * @throws LabelException when the printer cannot be reached, answers with an HTTP error
     *                        or rejects the request
     */
    private function send(string $request): array
    {
        try {
            $response = (new Client())->post($this->getUri('http'), [
                'headers'         => ['Content-Type' => 'application/ipp'],
                'body'            => $request,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout'         => self::TIMEOUT,
                'http_errors'     => false,
            ]);
        } catch (GuzzleException) {
            throw new LabelException(sprintf(
                __('The printer at %s cannot be reached. Check the address and that the printer is on.', 'assetlabel'),
                $this->host,
            ));
        }
        if ($response->getStatusCode() !== 200) {
            throw new LabelException(sprintf(
                __('The printer at %1$s does not accept IPP requests (HTTP error %2$d).', 'assetlabel'),
                $this->host,
                $response->getStatusCode(),
            ));
        }

        $result = Ipp::decode((string) $response->getBody());
        // Status codes 0x0000-0x00FF are successful
        if ($result['status'] > 0x00FF) {
            $message = $result['attributes']['status-message'][0] ?? null;
            throw new LabelException(sprintf(
                __('The printer rejected the request: %s', 'assetlabel'),
                is_string($message) && $message !== '' ? $message : sprintf('0x%04X', $result['status']),
            ));
        }
        return $result['attributes'];
    }
}
