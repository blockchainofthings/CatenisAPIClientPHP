<?php
/**
 * Created by claudio on 2018-12-06
 */

namespace Catenis\Exception;

use Exception;


/**
 * Class WsNotificationException - Base exception returned by WebSocket notification channel services
 *                                  of the Catenis API
 * @package Catenis\Exception
 */
class WsNotificationException extends CatenisException {
    /**
     * WsNotificationException constructor.
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message = "", $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}