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

use Glpi\Exception\Http\BadRequestHttpException;

/**
 * A label cannot be generated or printed; the message is translated and safe to show to the user.
 */
final class LabelException extends BadRequestHttpException
{
    /**
     * Uses the message both as the exception message and as the message shown to the user.
     *
     * @param string $message translated message
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
        $this->setMessageToDisplay($message);
    }
}
