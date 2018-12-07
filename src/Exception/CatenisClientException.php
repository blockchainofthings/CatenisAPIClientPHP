<?php
/**
 * Created by claudio on 2018-11-24
 */

namespace Catenis\Exception;

use Exception;


/**
 * Class CatenisClientException - Exception returned when an error takes place while trying to call
 *      one of the Catenis API endpoints
 * @package Catenis
 */
class CatenisClientException extends CatenisException {
    /**
     * CatenisClientException constructor.
     * @param string $message
     * @param Exception|null $previous
     */
    public function __construct($message = "", Exception $previous = null) {
        $innerMessage = isset($previous) ? ': ' . $previous->getMessage() : (isset($message) ? ': ' . $message : '');

        parent::__construct('Error calling Catenis API endpoint' . $innerMessage, 0, $previous);
    }
}