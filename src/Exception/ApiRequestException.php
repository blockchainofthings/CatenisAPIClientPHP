<?php
/**
 * Created by claudio on 2018-11-24
 */

namespace Catenis\Exception;

use Exception;


/**
 * Class ApiRequestException - Exception returned when an error takes place while trying to call
 *      on of the Catenis API endpoints
 * @package Catenis
 */
class ApiRequestException extends ApiClientException {
    public function __construct(Exception $previous) {
        parent::__construct('Error calling Catenis API endpoint: ' . $previous->getMessage(), 0, $previous);
    }
}
