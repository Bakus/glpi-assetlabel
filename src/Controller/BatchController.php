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
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Assetlabel\Batch;
use GlpiPlugin\Assetlabel\LabelFile;
use Html;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Result of the "Print labels" bulk action: a page that starts the download automatically.
 */
#[SecurityStrategy(Firewall::STRATEGY_CENTRAL_ACCESS)]
final class BatchController extends AbstractController
{
    private const TOKEN = '[a-f0-9]{32}';

    /**
     * Page that downloads the batch file automatically and links back to the list.
     *
     * @param string $token batch token
     *
     * @return Response
     *
     * @throws NotFoundHttpException when the batch is unknown or its file has been removed
     */
    #[Route('/batch/{token}', name: 'batch', requirements: ['token' => self::TOKEN], methods: 'GET')]
    public function page(string $token): Response
    {
        $batch = $this->getBatch($token);

        return $this->render('@assetlabel/batch.html.twig', [
            'title'        => __('Labels are ready', 'assetlabel'),
            'menu'         => ['assets'],
            'filename'     => $batch->filename,
            'download_url' => Html::getPrefixedUrl('/plugins/assetlabel/batch/' . $token . '/file'),
            'back_url'     => $batch->back_url,
        ]);
    }

    /**
     * The batch file as a download.
     *
     * @param string $token batch token
     *
     * @return Response
     *
     * @throws NotFoundHttpException when the batch is unknown or its file has been removed
     */
    #[Route('/batch/{token}/file', name: 'batch_file', requirements: ['token' => self::TOKEN], methods: 'GET')]
    public function file(string $token): Response
    {
        $batch = $this->getBatch($token);

        $response = new BinaryFileResponse($batch->path);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $batch->filename);
        $response->headers->set('Content-Type', LabelFile::mimeType($batch->filename));
        return $response;
    }

    /**
     * Batch of the current session.
     *
     * @param string $token batch token
     *
     * @return Batch
     *
     * @throws NotFoundHttpException when the batch is unknown or its file has been removed
     */
    private function getBatch(string $token): Batch
    {
        $batch = Batch::find($token);
        if ($batch === null) {
            $exception = new NotFoundHttpException();
            $exception->setMessageToDisplay(
                __('This file is no longer available. Please generate the labels again.', 'assetlabel'),
            );
            throw $exception;
        }
        return $batch;
    }
}
