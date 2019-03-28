<?php
/**
 * Created by claudio on 2018-12-06
 */

namespace Catenis\Exception;

use Exception;

/**
 * Class OpenWsConnException - Exception returned when an error takes place while establishing
 *                              the WebSocket connection to be used by the notification channel
 * @package Catenis\Exception
 */
class OpenWsConnException extends WsNotificationException
{
    /**
     * OpenWsConnException constructor.
     * @param string $message
     * @param Exception|null $previous
     */
    public function __construct($message = "", Exception $previous = null)
    {
        parent::__construct(
            (empty($message) ? 'Error establishing WebSocket connection' : $message) . (isset($previous) ? ': '
                . $previous->getMessage() : ''),
            0,
            $previous
        );
    }
}
