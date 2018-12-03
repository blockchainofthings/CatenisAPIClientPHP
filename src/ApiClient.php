<?php
/**
 * Created by claudio on 2018-11-21
 */

namespace Catenis;

use stdClass;
use Exception;
use DateTime;
use DateInterval;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\UriNormalizer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use WyriHaximus\React\GuzzlePsr7\HttpClientAdapter;
use Catenis\Internal\ApiVersion;
use Catenis\Exception\CatenisException;
use Catenis\Exception\CatenisClientException;
use Catenis\Exception\CatenisApiException;


class ApiClient {
    private static $apiPath = '/api/';
    private static $signVersionId = 'CTN1';
    private static $signMethodId = 'CTN1-HMAC-SHA256';
    private static $scopeRequest = 'ctn1_request';
    private static $timestampHdr = 'X-BCoT-Timestamp';
    private static $signValidDays = 7;

    private $rootApiEndPoint;
    private $deviceId;
    private $apiAccessSecret;
    private $lastSignDate;
    private $lastSignKey;
    private $signValidPeriod;
    private $httpClient;

    /**
     * @param string $methodPath - The URI path of the API endpoint
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of the url parameters
     *      that are to be substituted and the values the values to be used for the substitution
     * @return mixed - The formatted URI path
     */
    private static function formatMethodPath(&$methodPath, array $urlParams = null) {
        $formattedPath = $methodPath;

        if ($urlParams !== null) {
            foreach ($urlParams as $key => $value) {
                $formattedPath = preg_replace("/:$key\\b/", $value, $formattedPath);
            }
        }

        return $formattedPath;
    }

    /**
     * Generate a SHA256 hash for a given byte sequence
     * @param string $data
     * @return string - The generated hash
     */
    private static function hashData($data) {
        return hash('sha256', $data);
    }

    /**
     * Signs a byte sequence with a given secret key
     * @param string $data - The data to be signed
     * @param string $secret - The key to be used for signing
     * @param bool $hexEncode - Indicates whether the output should be hex encoded
     * @return string - The generated signature
     */
    private static function signData($data, $secret, $hexEncode = false) {
        return hash_hmac('sha256', $data, $secret, !$hexEncode);
    }

    /**
     * Process response from HTTP request
     * @param ResponseInterface $response - The HTTP response
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private static function processResponse(ResponseInterface $response) {
        // Process response
        $body = (string)$response->getBody();
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            // Error returned from API endpoint. Retrieve Catenis API error message if returned
            $ctnErrorMessage = null;

            if (!empty($body)) {
                $jsonBody = json_decode($body);

                if ($jsonBody !== null && is_object($jsonBody) && isset($jsonBody->status) && isset($jsonBody->message)) {
                    $ctnErrorMessage = $jsonBody->message;
                }
            }

            // Throw API response exception
            throw new CatenisApiException($response->getReasonPhrase(), $statusCode, $ctnErrorMessage);
        }

        // Validate and return data returned as response
        if (!empty($body)) {
            $jsonBody = json_decode($body);

            if ($jsonBody !== null && is_object($jsonBody) && isset($jsonBody->status) && $jsonBody->status === 'success' && isset($jsonBody->data)) {
                // Return the data
                return $jsonBody->data;
            }
        }

        // Invalid data returned. Throw exception
        throw new CatenisClientException("Unexpected response returned by API endpoint: $body");
    }

    /**
     * Set up request parameters for Log Message API endpoint
     * @param string $message
     * @param array|null $options
     * @return array
     */
    private static function logMessageRequestParams($message, array $options = null) {
        $jsonData = new stdClass();

        $jsonData->message = $message;

        if ($options !== null) {
            $jsonData->options = $options;
        }

        return [
            'messages/log',
            $jsonData
        ];
    }

    /**
     * Set up request parameters for Send Message API endpoint
     * @param array $targetDevice
     * @param string $message
     * @param array|null $options
     * @return array
     */
    private static function sendMessageRequestParams(array $targetDevice, $message, array $options = null) {
        $jsonData = new stdClass();

        $jsonData->message = $message;
        $jsonData->targetDevice = $targetDevice;

        if ($options !== null) {
            $jsonData->options = $options;
        }

        return [
            'messages/send',
            $jsonData
        ];
    }

    /**
     * Set up request parameters for Read Message API endpoint
     * @param string $messageId
     * @param string|null $encoding
     * @return array
     */
    private static function readMessageRequestParams($messageId, $encoding = null) {
        $queryParams = null;

        if ($encoding !== null) {
            $queryParams = [
                'encoding' => $encoding
            ];
        }

        return [
            'messages/:messageId', [
                'messageId' => $messageId
            ],
            $queryParams
        ];
    }

    /**
     * Set up request parameters for Retrieve Message Container API endpoint
     * @param string $messageId
     * @return array
     */
    private static function retrieveMessageContainerRequestParams($messageId) {
        return [
            'messages/:messageId/container', [
                'messageId' => $messageId
            ]
        ];
    }

    /**
     * Set up request parameters fro List Messages API endpoint
     * @param array|null $options
     * @return array
     */
    private static function listMessagesRequestParams(array $options = null) {
        $queryParams = null;

        if ($options !== null) {
            $queryParams = [];

            if (isset($options['action'])) {
                $queryParams['action'] = $options['action'];
            }

            if (isset($options['direction'])) {
                $queryParams['direction'] = $options['direction'];
            }

            if (isset($options['fromDevices'])) {
                // Process from devices list
                $fromDevices = $options['fromDevices'];

                if (is_array($fromDevices)) {
                    $deviceIds = [];
                    $prodUniqueIds = [];

                    foreach ($fromDevices as $device) {
                        if (is_array($device) && isset($device['id'])) {
                            $id = $device['id'];

                            if (is_string($id) && !empty($id)) {
                                if (isset($device['isProdUniqueId']) && (bool)$device['isProdUniqueId']) {
                                    // This is actually a product unique ID. So add it to the proper list
                                    $prodUniqueIds[] = $id;
                                }
                                else {
                                    // Add device ID to list
                                    $deviceIds[] = $id;
                                }
                            }
                        }
                    }

                    if (!empty($deviceIds)) {
                        // Add list of from device IDs
                        $queryParams['fromDeviceIds'] = implode(',', $deviceIds);
                    }

                    if (!empty($prodUniqueIds)) {
                        // Add list of from device product unique IDs
                        $queryParams['fromDeviceProdUniqueIds'] = implode(',', $prodUniqueIds);
                    }
                }
            }

            if (isset($options['toDevices'])) {
                // Process to devices list
                $toDevices = $options['toDevices'];

                if (is_array($toDevices)) {
                    $deviceIds = [];
                    $prodUniqueIds = [];

                    foreach ($toDevices as $device) {
                        if (is_array($device) && isset($device['id'])) {
                            $id = $device['id'];

                            if (is_string($id) && !empty($id)) {
                                if (isset($device['isProdUniqueId']) && (bool)$device['isProdUniqueId']) {
                                    // This is actually a product unique ID. So add it to the proper list
                                    $prodUniqueIds[] = $id;
                                }
                                else {
                                    // Add device ID to list
                                    $deviceIds[] = $id;
                                }
                            }
                        }
                    }

                    if (!empty($deviceIds)) {
                        // Add list of to device IDs
                        $queryParams['toDeviceIds'] = implode(',', $deviceIds);
                    }

                    if (!empty($prodUniqueIds)) {
                        // Add list of to device product unique IDs
                        $queryParams['toDeviceProdUniqueIds'] = implode(',', $prodUniqueIds);
                    }
                }
            }

            if (isset($options['readState'])) {
                $queryParams['readState'] = $options['readState'];
            }

            if (isset($options['startDate'])) {
                $startDate = $options['startDate'];

                if (is_string($startDate) && !empty($startDate)) {
                    $queryParams['action'] = $startDate;
                }
                else if ($startDate instanceof DateTime) {
                    $queryParams['action'] = $startDate->format(DateTime::ISO8601);
                }
            }

            if (isset($options['endDate'])) {
                $endDate = $options['endDate'];

                if (is_string($endDate) && !empty($endDate)) {
                    $queryParams['action'] = $endDate;
                }
                else if ($endDate instanceof DateTime) {
                    $queryParams['action'] = $endDate->format(DateTime::ISO8601);
                }
            }
        }

        return [
            'messages',
            null,
            $queryParams
        ];
    }

    /**
     * Signs an HTTP request to an API endpoint adding the proper HTTP headers
     * @param RequestInterface &$request - The request to be signed
     * @throws Exception
     */
    private function signRequest(RequestInterface &$request) {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $timeStamp = $now->format('Ymd\THis\Z');

        /** @noinspection PhpUndefinedMethodInspection */
        if ($this->lastSignDate !== null && $this->lastSignDate->diff($now) < $this->signValidPeriod) {
            $useSameSignKey = $this->lastSignKey !== null;
        }
        else {
            $this->lastSignDate = $now;
            $useSameSignKey = false;
        }

        $signDate = $this->lastSignDate->format('Ymd');

        $request = $request->withHeader(self::$timestampHdr, $timeStamp);

        // First step: compute conformed request
        /** @noinspection PhpUndefinedMethodInspection */
        $confReq = $request->getMethod() . PHP_EOL;
        /** @noinspection PhpUndefinedMethodInspection */
        $confReq .= $request->getRequestTarget() . PHP_EOL;

        $essentialHeaders = 'host:' . $request->getHeaderLine('Host') . PHP_EOL;
        $essentialHeaders .= strtolower(self::$timestampHdr) . ':' . $request->getHeaderLine(self::$timestampHdr) . PHP_EOL;

        $confReq .= $essentialHeaders . PHP_EOL;
        $confReq .= self::hashData((string)$request->getBody()) . PHP_EOL;

        // Second step: assemble string to sign
        $strToSign = self::$signMethodId . PHP_EOL;
        $strToSign .= $timeStamp . PHP_EOL;

        $scope = $signDate . '/' . self::$scopeRequest;

        $strToSign .= $scope . PHP_EOL;
        $strToSign .= self::hashData($confReq) . PHP_EOL;

        // Third step: generate the signature
        if ($useSameSignKey) {
            $signKey = $this->lastSignKey;
        }
        else {
            $dateKey = self::signData($signDate, self::$signVersionId . $this->apiAccessSecret);
            $signKey = $this->lastSignKey = self::signData(self::$scopeRequest, $dateKey);
        }

        $credential = $this->deviceId . '/' . $scope;
        $signature = self::signData($strToSign, $signKey, true);

        // Step four: add authorization header
        $request = $request->withHeader('Authorization', self::$signMethodId . ' Credential=' . $credential . ', Signature=' . $signature);
    }

    /** @noinspection PhpDocMissingThrowsInspection, PhpDocRedundantThrowsInspection */
    /**
     * Sends a request to an API endpoint
     * @param RequestInterface $request - The request to send
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private function sendRequest(RequestInterface $request) {
        try {
            // Sign and send request
            $this->signRequest($request);

            /** @noinspection PhpUnhandledExceptionInspection */
            $response = $this->httpClient->send($request);

            // Process response
            return self::processResponse($response);
        }
        catch (CatenisException $apiEx) {
            // Just rethrows exception
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $apiEx;
        }
        catch (Exception $ex) {
            // Exception processing request. Throws local exception
            throw new CatenisClientException(null, $ex);
        }
    }

    /**
     * Sends a request to an API endpoint asynchronously
     * @param RequestInterface $request - The request to send
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendRequestAsync(RequestInterface $request) {
        $promise = Promise\task(function () use (&$request) {
            try {
                // Sign and send request
                $this->signRequest($request);

                return $this->httpClient->sendAsync($request)->then(
                    function (ResponseInterface $response) {
                        // Process response
                        return self::processResponse($response);
                    },
                    function (Exception $ex) {
                        // Exception while sending request. Rethrow local exception
                        throw new CatenisClientException(null, $ex);
                    }
                );
            }
            catch (Exception $ex) {
                // Exception processing request. Throws local exception
                throw new CatenisClientException(null, $ex);
            }
        });

        return $promise;
    }

    /**
     * Assembles the complete URL for an API endpoint
     * @param string $methodPath
     * @param array|null $urlParams
     * @param array|null $queryParams
     * @return UriInterface
     */
    private function assembleMethodEndPointUrl($methodPath, array $urlParams = null, array $queryParams = null) {
        $methodEndPointUrl = new Uri(self::formatMethodPath($methodPath, $urlParams));

        if ($queryParams !== null) {
            foreach ($queryParams as $key => $value) {
                $methodEndPointUrl = Uri::withQueryValue($methodEndPointUrl, $key, $value);
            }
        }

        // Make sure that duplicate slashes that might occur in the URL (due to empty URL parameters)
        //  are reduced to a single slash so the URL used for signing is not different from the
        //  actual URL of the sent request
        $methodEndPointUrl = UriNormalizer::normalize(UriResolver::resolve($this->rootApiEndPoint, $methodEndPointUrl), UriNormalizer::REMOVE_DUPLICATE_SLASHES);

        return $methodEndPointUrl;
    }

    /**
     * Sends a GET request to a given API endpoint
     * @param $methodPath - The (relative) path to the API endpoint
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private function sendGetRequest($methodPath, array $urlParams = null, array $queryParams = null) {
        // Prepare request
        $request = new Request('GET', $this->assembleMethodEndPointUrl($methodPath, $urlParams, $queryParams));

        // Sign and send the request
        return $this->sendRequest($request);
    }

    /**
     * Sends a GET request to a given API endpoint asynchronously
     * @param $methodPath - The (relative) path to the API endpoint
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendGetRequestAsync($methodPath, array $urlParams = null, array $queryParams = null) {
        // Prepare request
        $request = new Request('GET', $this->assembleMethodEndPointUrl($methodPath, $urlParams, $queryParams));

        // Sign and send the request asynchronously
        return $this->sendRequestAsync($request);
    }

    /**
     * Sends a GET request to a given API endpoint
     * @param $methodPath - The (relative) path to the API endpoint
     * @param stdClass $jsonData - An object representing the JSON formatted data is to be sent with the request
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private function sendPostRequest($methodPath, stdClass $jsonData, array $urlParams = null, array $queryParams = null) {
        // Prepare request
        $request = new Request('POST', $this->assembleMethodEndPointUrl($methodPath, $urlParams, $queryParams),
                ['Content-Type' => 'application/json'], json_encode($jsonData));

        // Sign and send the request
        return $this->sendRequest($request);
    }

    /**
     * Sends a GET request to a given API endpoint asynchronously
     * @param $methodPath - The (relative) path to the API endpoint
     * @param stdClass $jsonData - An object representing the JSON formatted data is to be sent with the request
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendPostRequestAsync($methodPath, stdClass $jsonData, array $urlParams = null, array $queryParams = null) {
        $request = new Request('POST', $this->assembleMethodEndPointUrl($methodPath, $urlParams, $queryParams),
                ['Content-Type' => 'application/json'], json_encode($jsonData));

        // Sign and send the request
        return $this->sendRequestAsync($request);
    }

    /**
     * ApiClient constructor.
     * @param string $deviceId
     * @param string $apiAccessSecret
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'host' => [string]           - (optional, default: 'catenis.io') Host name (with optional port) of target Catenis API server
     *      'environment' => [string]    - (optional, default: 'prod') Environment of target Catenis API server. Valid values: 'prod', 'sandbox' (or 'beta')
     *      'secure' => [bool]           - (optional, default: true) Indicates whether a secure connection (HTTPS) should be used
     *      'version' => [string]        - (optional, default: '0.6') Version of Catenis API to target
     *      'timeout' => [float|integer] - (optional, default: 0, no timeout) Timeout, in seconds, to wait for a response
     *      'eventLoop' => [EventLoop\Loo pInterface] - (optional) Event loop to be used for asynchronous API method calling mechanism
     *      'pumpTaskQueue' => [bool] - (optional, default: true) Indicates whether to force the promise task queue to be periodically (on every event
     *                                      loop tick) run. Note that, if this option is set to false, the user should be responsible to periodically
     *                                      run the task queue by his/her own. This option is only processed when an event loop is provided
     * @throws Exception
     */
    function __construct($deviceId, $apiAccessSecret, array $options = null) {
        $hostName = 'catenis.io';
        $subdomain = '';
        $secure = true;
        $version = '0.6';
        $timeout = 0;
        $httpClientHandler = null;

        if ($options !== null) {
            if (isset($options['host'])) {
                $optHost = $options['host'];

                if (is_string($optHost) && !empty($optHost)) {
                    $hostName = $optHost;
                }
            }

            if (isset($options['environment'])) {
                $optEnv = $options['environment'];

                if ($optEnv === 'sandbox' || $optEnv === 'beta') {
                    $subdomain = 'sandbox.';
                }
            }

            if (isset($options['secure'])) {
                $optSec = $options['secure'];

                if (is_bool($optSec)) {
                    $secure = $optSec;
                }
            }
            
            if (isset($options['version'])) {
                $optVer = $options['version'];

                if (is_string($optVer) && !empty($optVer)) {
                    $version = $optVer;
                }
            }

            if (isset($options['timeout'])) {
                $optTimeout = $options['timeout'];

                if ((is_double($optTimeout) || is_int($optTimeout)) && $optTimeout > 0) {
                    $timeout = $optTimeout;
                }
            }

            if (isset($options['eventLoop'])) {
                $optEventLoop = $options['eventLoop'];

                if ($optEventLoop instanceof \React\EventLoop\LoopInterface) {
                    // React event loop passed.
                    //  Set up specific HTTP client handler for processing asynchronous requests
                    $httpClientHandler = HandlerStack::create(new HttpClientAdapter($optEventLoop));

                    // Converts timeout for waiting indefinitely
                    if ($timeout == 0) {
                        $timeout = -1;
                    }

                    $pumpTaskQueue = true;

                    if (isset($options['pumpTaskQueue'])) {
                        $optPumpEventLoop = $options['pumpTaskQueue'];

                        if (is_bool($optPumpEventLoop)) {
                            $pumpTaskQueue = $optPumpEventLoop;
                        }
                    }

                    if ($pumpTaskQueue) {
                        $queue = Promise\queue();
                        $optEventLoop->addPeriodicTimer(0, [$queue, 'run']);
                    }
                }
            }
        }

        /** @noinspection PhpUnusedLocalVariableInspection */
        $apiVersion = new ApiVersion($version);

        $host = $subdomain . $hostName;
        $uriPrefix = ($secure ? 'https://' : 'http://') . $host;
        $apiBaseUriPath = self::$apiPath . $version . '/';
        $this->rootApiEndPoint = new Uri($uriPrefix . $apiBaseUriPath);
        $this->deviceId = $deviceId;
        $this->apiAccessSecret = $apiAccessSecret;
        $this->signValidPeriod = new DateInterval(sprintf('P%dD', self::$signValidDays));

        // Instantiate HTTP client
        $this->httpClient = new Client([
            'handler' => $httpClientHandler,
            RequestOptions::HEADERS => [
                'User-Agent' => 'Catenis API client for PHP',
                'Accept' => 'application/json'
            ],
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => $timeout
        ]);
    }

    // Synchronous processing methods
    //
    /**
     * Log a message
     * @param string $message - The message to store
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'encoding' => [string],  (optional, default: 'utf8') One of the following values identifying the encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [boolean],  (optional, default: true) Indicates whether message should be encrypted before storing
     *      'storage' => [string]    (optional, default: 'auto') One of the following values identifying where the message should be stored: 'auto'|'embedded'|'external'
     * @return stdClass - An object representing the JSON formatted data returned by the Log Message Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function logMessage($message, array $options = null) {
        return $this->sendPostRequest(...self::logMessageRequestParams($message, $options));
    }

    /**
     * Send a message
     * @param array $targetDevice - A map (associative array) containing the following keys:
     *      'id' => [string],               ID of target device. Should be Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]   (optional, default: false) Indicate whether supply ID is a product unique ID (otherwise, it should be a Catenis device Id)
     * @param string $message - The message to send
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'readConfirmation' => [boolean], (optional, default: false) Indicates whether message should be sent with read confirmation enabled
     *      'encoding' => [string],          (optional, default: 'utf8') One of the following values identifying the encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [boolean],          (optional, default: true) Indicates whether message should be encrypted before storing
     *      'storage' => [string]            (optional, default: 'auto') One of the following values identifying where the message should be stored: 'auto'|'embedded'|'external'
     * @return stdClass - An object representing the JSON formatted data returned by the Log Message Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function sendMessage(array $targetDevice, $message, array $options = null) {
        return $this->sendPostRequest(...self::sendMessageRequestParams($targetDevice, $message, $options));
    }

    /**
     * Read a message
     * @param string $messageId - The ID of the message to read
     * @param string|null $encoding - (default: 'utf8') One of the following values identifying the encoding that should be used for the returned message: 'utf8'|'base64'|'hex'
     * @return stdClass - An object representing the JSON formatted data returned by the Read Message Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function readMessage($messageId, $encoding = null) {
        return $this->sendGetRequest(...self::readMessageRequestParams($messageId, $encoding));
    }

    /**
     * Retrieve message container
     * @param string $messageId - The ID of message to retrieve container info
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Message Container Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function retrieveMessageContainer($messageId) {
        return $this->sendGetRequest(...self::retrieveMessageContainerRequestParams($messageId));
    }

    /**
     * List messages
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'action' => [string]                  (optional, default: 'any') - One of the following values specifying the action originally performed on
     *                                             the messages intended to be retrieved: 'log'|'send'|'any'
     *      'direction' => [string]               (optional, default: 'any') - One of the following values specifying the direction of the sent messages
     *                                             intended to be retrieve: 'inbound'|'outbound'|'any'. Note that this option only applies to
     *                                             sent messages (action = 'send'). 'inbound' indicates messages sent to the device that issued
     *                                             the request, while 'outbound' indicates messages sent from the device that issued the request
     *      'fromDevices' => [array]              (optional) - A list (simple array) of devices from which the messages intended to be retrieved had been sent.
     *                                             Note that this option only applies to messages sent to the device that issued the request (action = 'send' and direction = 'inbound')
     *          [n] => [array]                     Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]                ID of the device. Should be Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]   (optional, default: false) Indicate whether supplied ID is a product unique ID (otherwise, it should be a Catenis device Id)
     *
     *      'toDevices' => [array]                (optional) - A list (simple array) of devices to which the messages intended to be retrieved had been sent.
     *                                             Note that this option only applies to messages sent from the device that issued the request (action = 'send' and direction = 'outbound')
     *          [n] => [array]                     Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]                ID of the device. Should be Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]   (optional, default: false) Indicate whether supplied ID is a product unique ID (otherwise, it should be a Catenis device Id)
     *
     *      'readState' => [string]               (optional, default: 'any') - One of the following values indicating the current read state of the
     *                                             the messages intended to be retrieved: 'unread'|'read'|'any'.
     *      'startDate' => [string|DateTime]      (optional) - Date and time specifying the lower boundary of the time frame within
     *                                             which the messages intended to be retrieved has been: logged, in case of messages logged
     *                                             by the device that issued the request (action = 'log'); sent, in case of messages sent from the current
     *                                             device (action = 'send' direction = 'outbound'); or received, in case of messages sent to
     *                                             the device that issued the request (action = 'send' and direction = 'inbound').
     *                                             Note: if a string is passed, assumes that it is an ISO8601 formatter date/time
     *      'endDate' => [string|DateTime]        (optional) - Date and time specifying the upper boundary of the time frame within
     *                                             which the messages intended to be retrieved has been: logged, in case of messages logged
     *                                             by the device that issued the request (action = 'log'); sent, in case of messages sent from the current
     *                                             device (action = 'send' direction = 'outbound'); or received, in case of messages sent to
     *                                             the device that issued the request (action = 'send' and direction = 'inbound')
     *                                             Note: if a string is passed, assumes that it is an ISO8601 formatter date/time
     * @return stdClass - An object representing the JSON formatted data returned by the List Messages Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listMessages(array $options = null) {
        return $this->sendGetRequest(...self::listMessagesRequestParams($options));
    }

    // Asynchronous processing methods
    //
    /**
     * Log a message asynchronously
     * @param string $message - The message to store
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'encoding' => [string],  (optional, default: 'utf8') One of the following values identifying the encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [boolean],  (optional, default: true) Indicates whether message should be encrypted before storing
     *      'storage' => [string]    (optional, default: 'auto') One of the following values identifying where the message should be stored: 'auto'|'embedded'|'external'
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    function logMessageAsync($message, array $options = null) {
        return $this->sendPostRequestAsync(...self::logMessageRequestParams($message, $options));
    }

    /**
     * Send a message asynchronously
     * @param array $targetDevice - A map (associative array) containing the following keys:
     *      'id' => [string],               ID of target device. Should be Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]   (optional, default: false) Indicate whether supply ID is a product unique ID (otherwise, it should be a Catenis device Id)
     * @param string $message - The message to send
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'readConfirmation' => [boolean], (optional, default: false) Indicates whether message should be sent with read confirmation enabled
     *      'encoding' => [string],          (optional, default: 'utf8') One of the following values identifying the encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [boolean],          (optional, default: true) Indicates whether message should be encrypted before storing
     *      'storage' => [string]            (optional, default: 'auto') One of the following values identifying where the message should be stored: 'auto'|'embedded'|'external'
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    function sendMessageAsync(array $targetDevice, $message, array $options = null) {
        return $this->sendPostRequestAsync(...self::sendMessageRequestParams($targetDevice, $message, $options));
    }

    /**
     * Read a message asynchronously
     * @param string $messageId - The ID of the message to read
     * @param string|null $encoding - (default: 'utf8') One of the following values identifying the encoding that should be used for the returned message: 'utf8'|'base64'|'hex'
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    function readMessageAsync($messageId, $encoding = null) {
        return $this->sendGetRequestAsync(...self::readMessageRequestParams($messageId, $encoding));
    }

    /**
     * Retrieve message container asynchronously
     * @param string $messageId - The ID of message to retrieve container info
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    function retrieveMessageContainerAsync($messageId) {
        return $this->sendGetRequestAsync(...self::retrieveMessageContainerRequestParams($messageId));
    }

    /**
     * List messages asynchronously
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'action' => [string]                  (optional, default: 'any') - One of the following values specifying the action originally performed on
     *                                             the messages intended to be retrieved: 'log'|'send'|'any'
     *      'direction' => [string]               (optional, default: 'any') - One of the following values specifying the direction of the sent messages
     *                                             intended to be retrieve: 'inbound'|'outbound'|'any'. Note that this option only applies to
     *                                             sent messages (action = 'send'). 'inbound' indicates messages sent to the device that issued
     *                                             the request, while 'outbound' indicates messages sent from the device that issued the request
     *      'fromDevices' => [array]              (optional) - A list (simple array) of devices from which the messages intended to be retrieved had been sent.
     *                                             Note that this option only applies to messages sent to the device that issued the request (action = 'send' and direction = 'inbound')
     *          [n] => [array]                     Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]                ID of the device. Should be Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]   (optional, default: false) Indicate whether supplied ID is a product unique ID (otherwise, it should be a Catenis device Id)
     *
     *      'toDevices' => [array]                (optional) - A list (simple array) of devices to which the messages intended to be retrieved had been sent.
     *                                             Note that this option only applies to messages sent from the device that issued the request (action = 'send' and direction = 'outbound')
     *          [n] => [array]                     Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]                ID of the device. Should be Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]   (optional, default: false) Indicate whether supplied ID is a product unique ID (otherwise, it should be a Catenis device Id)
     *
     *      'readState' => [string]               (optional, default: 'any') - One of the following values indicating the current read state of the
     *                                             the messages intended to be retrieved: 'unread'|'read'|'any'.
     *      'startDate' => [string|DateTime]      (optional) - Date and time specifying the lower boundary of the time frame within
     *                                             which the messages intended to be retrieved has been: logged, in case of messages logged
     *                                             by the device that issued the request (action = 'log'); sent, in case of messages sent from the current
     *                                             device (action = 'send' direction = 'outbound'); or received, in case of messages sent to
     *                                             the device that issued the request (action = 'send' and direction = 'inbound').
     *                                             Note: if a string is passed, it should be an ISO8601 formatter date/time
     *      'endDate' => [string|DateTime]        (optional) - Date and time specifying the upper boundary of the time frame within
     *                                             which the messages intended to be retrieved has been: logged, in case of messages logged
     *                                             by the device that issued the request (action = 'log'); sent, in case of messages sent from the current
     *                                             device (action = 'send' direction = 'outbound'); or received, in case of messages sent to
     *                                             the device that issued the request (action = 'send' and direction = 'inbound')
     *                                             Note: if a string is passed, it should be an ISO8601 formatter date/time
     * @return Promise\PromiseInterface - A promise representing the asynchronous processing
     */
    function listMessagesAsync(array $options = null) {
        return $this->sendGetRequestAsync(...self::listMessagesRequestParams($options));
    }
}