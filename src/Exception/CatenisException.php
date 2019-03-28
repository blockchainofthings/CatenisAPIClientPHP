<?php
/**
 * Created by claudio on 2018-11-24
 */

namespace Catenis\Exception;

use Exception;

/**
 * Class CatenisException - Base exception returned by Catenis API client
 * @package Catenis
 */
class CatenisException extends Exception
{
    /**
     * CatenisException constructor.
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message = "", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
