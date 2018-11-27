<?php
/**
 * Created by claudio on 2018-11-24
 */

namespace Catenis\Exception;


/**
 * Class ApiResponseException - Exception returned when an error is returned by the Catenis API endpoint
 * @package Catenis
 */
class ApiResponseException extends ApiClientException {
    private $httpStatusMessage;
    private $httpStatusCode;
    private $ctnErrorMessage;

    public function __construct($httpStatusMessage, $httpStatusCode, $ctnErrorMessage = null) {
        $this->httpStatusMessage = $httpStatusMessage;
        $this->httpStatusCode = $httpStatusCode;
        $this->ctnErrorMessage = $ctnErrorMessage;

        parent::__construct("Error returned from Catenis API endpoint: [$httpStatusCode] " . ($ctnErrorMessage !== null ? $ctnErrorMessage : $httpStatusMessage), $httpStatusCode);
    }

    public function getHttpStatusMessage() {
        return $this->httpStatusMessage;
    }

    public function getHttpStatusCode() {
        return $this->httpStatusCode;
    }

    public function getCatenisErrorMessage() {
        return $this->ctnErrorMessage;
    }
}
