<?php
/**
 * Created by claudio on 2018-11-24
 */

namespace Catenis\Exception;

use Exception;


/**
 * Class ApiClientException - Base exception returned by Catenis API client
 * @package Catenis
 */
class ApiClientException extends Exception {
    /**
     * ApiClientException constructor.
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message = "", $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
