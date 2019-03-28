<?php
/**
 * Created by claudio on 2018-12-06
 */

namespace Catenis\Exception;

/**
 * Class WsNotifyChannelAlreadyOpenException - Exception returned when trying to open a WebSocket notification
 *                                              channel that is already open
 * @package Catenis\Exception
 */
class WsNotifyChannelAlreadyOpenException extends WsNotificationException
{
    public function __construct($message = "")
    {
        parent::__construct(empty($message) ? 'WebSocket notification channel already open' : $message);
    }
}
