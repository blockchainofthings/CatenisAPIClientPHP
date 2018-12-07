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
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\UriNormalizer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use WyriHaximus\React\GuzzlePsr7\HttpClientAdapter;
use Catenis\Notification\WsNotifyChannel;
use Catenis\Internal\ApiVersion;
use Catenis\Internal\ServiceType;
use Catenis\Internal\ApiPackage;
use Catenis\Exception\CatenisException;
use Catenis\Exception\CatenisClientException;
use Catenis\Exception\CatenisApiException;


class ApiClient extends ApiPackage {
    private static $apiPath = '/api/';
    private static $signVersionId = 'CTN1';
    private static $signMethodId = 'CTN1-HMAC-SHA256';
    private static $scopeRequest = 'ctn1_request';
    private static $signValidDays = 7;
    private static $notifyRootPath = 'notify';
    private static $wsNtfyRootPath =  'ws';
    private static $timestampHdr = 'X-BCoT-Timestamp';
    private static $notifyWsSubprotocol = 'notify.catenis.io';

    protected $eventLoop;

    private $rootApiEndPoint;
    private $deviceId;
    private $apiAccessSecret;
    private $lastSignDate;
    private $lastSignKey;
    private $signValidPeriod;
    private $rootWsNtfyEndPoint;
    private $httpClient;

    /**
     * @return string
     */
    protected function getTimestampHeader() {
        return self::$timestampHdr;
    }

    /**
     * @return string
     */
    protected function getNotifyWsSubprotocol() {
        return self::$notifyWsSubprotocol;
    }

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
     * Set up request parameters for List Messages API endpoint
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
                    $queryParams['startDate'] = $startDate;
                }
                else if ($startDate instanceof DateTime) {
                    $queryParams['startDate'] = $startDate->format(DateTime::ISO8601);
                }
            }

            if (isset($options['endDate'])) {
                $endDate = $options['endDate'];

                if (is_string($endDate) && !empty($endDate)) {
                    $queryParams['endDate'] = $endDate;
                }
                else if ($endDate instanceof DateTime) {
                    $queryParams['endDate'] = $endDate->format(DateTime::ISO8601);
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
     * Set up request parameters for List Permission Events API endpoint
     * @return array
     */
    private static function listPermissionEventsRequestParams() {
        return [
            'permission/events'
        ];
    }

    /**
     * Set up request parameters for Retrieve Permission Rights API endpoint
     * @param string $eventName
     * @return array
     */
    private static function retrievePermissionRightsRequestParams($eventName) {
        return [
            'permission/events/:eventName/rights', [
                'eventName' => $eventName
            ]
        ];
    }

    /**
     * Set up request parameters for Set Permission Rights API endpoint
     * @param string $eventName
     * @param array $rights
     * @return array
     */
    private static function setPermissionRightsRequestParams($eventName, array $rights) {
        return [
            'permission/events/:eventName/rights', [
                'eventName' => $eventName
            ],
            (object)$rights
        ];
    }

    /**
     * Set up request parameters for Check Effective Permission Right API endpoint
     * @param string $eventName
     * @param string $deviceId
     * @param bool $isProdUniqueId
     * @return array
     */
    private static function checkEffectivePermissionRightRequestParams($eventName, $deviceId, $isProdUniqueId = false) {
        $queryParams = null;

        if ($isProdUniqueId) {
            $queryParams = [
                'isProdUniqueId' => true
            ];
        }

        return [
            'permission/events/:eventName/rights/:deviceId', [
                'eventName' => $eventName,
                'deviceId' => $deviceId
            ],
            $queryParams
        ];
    }

    /**
     * Set up request parameters for List Notification Events API endpoint
     * @return array
     */
    private static function listNotificationEventsRequestParams() {
        return [
            'notification/events'
        ];
    }

    /**
     * Set up request parameters for Retrieve Device Identification Info API endpoint
     * @param string $deviceId
     * @param bool $isProdUniqueId
     * @return array
     */
    private static function retrieveDeviceIdentificationInfoRequestParams($deviceId, $isProdUniqueId = false) {
        $queryParams = null;

        if ($isProdUniqueId) {
            $queryParams = [
                'isProdUniqueId' => true
            ];
        }

        return [
            'devices/:deviceId', [
                'deviceId' => $deviceId
            ],
            $queryParams
        ];
    }

    /**
     * Set up request parameters for Issue Asset API endpoint
     * @param array $assetInfo
     * @param float $amount
     * @param array|null $holdingDevice
     * @return array
     */
    private static function issueAssetRequestParams(array $assetInfo, $amount, array $holdingDevice = null) {
        $jsonData = new stdClass();

        $jsonData->assetInfo = $assetInfo;
        $jsonData->amount = $amount;

        if ($holdingDevice !== null) {
            $jsonData->holdingDevice = $holdingDevice;
        }

        return [
            'assets/issue',
            $jsonData
        ];
    }

    /**
     * Set up request parameters for Reissue Asset API endpoint
     * @param string $assetId
     * @param float $amount
     * @param array|null $holdingDevice
     * @return array
     */
    private static function reissueAssetRequestParams($assetId, $amount, array $holdingDevice = null) {
        $jsonData = new stdClass();

        $jsonData->amount = $amount;

        if ($holdingDevice !== null) {
            $jsonData->holdingDevice = $holdingDevice;
        }

        return [
            'assets/:assetId/issue',
            $jsonData, [
                'assetId' => $assetId
            ]
        ];
    }

    /**
     * Set up request parameters for Transfer Asset API endpoint
     * @param string $assetId
     * @param float $amount
     * @param array $receivingDevice
     * @return array
     */
    private static function transferAssetRequestParams($assetId, $amount, array $receivingDevice) {
        $jsonData = new stdClass();

        $jsonData->amount = $amount;
        $jsonData->receivingDevice = $receivingDevice;

        return [
            'assets/:assetId/transfer',
            $jsonData, [
                'assetId' => $assetId
            ]
        ];
    }

    /**
     * Set up request parameters for Retrieve Asset Info API endpoint
     * @param string $assetId
     * @return array
     */
    private static function retrieveAssetInfoRequestParams($assetId) {
        return [
            'assets/:assetId', [
                'assetId' => $assetId
            ]
        ];
    }

    /**
     * Set up request parameters for Get Asset Balance Info API endpoint
     * @param string $assetId
     * @return array
     */
    private static function getAssetBalanceRequestParams($assetId) {
        return [
            'assets/:assetId/balance', [
                'assetId' => $assetId
            ]
        ];
    }

    /**
     * Set up request parameters for List Owned Assets API endpoint
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listOwnedAssetsRequestParams($limit = null, $skip = null) {
        $queryParams = null;

        if ($limit !== null) {
            $queryParams = [
                'limit' => $limit
            ];
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
        }

        return [
            'assets/owned',
            null,
            $queryParams
        ];
    }

    /**
     * Set up request parameters for List Issued Assets API endpoint
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listIssuedAssetsRequestParams($limit = null, $skip = null) {
        $queryParams = null;

        if ($limit !== null) {
            $queryParams = [
                'limit' => $limit
            ];
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
        }

        return [
            'assets/issued',
            null,
            $queryParams
        ];
    }

    /**
     * Set up request parameters for Retrieve Asset Issuance History API endpoint
     * @param string $assetId
     * @param string|DateTime|null $startDate
     * @param string|DateTime|null $endDate
     * @return array
     */
    private static function retrieveAssetIssuanceHistoryRequestParams($assetId, $startDate = null, $endDate = null) {
        $queryParams = null;

        if ($startDate !== null) {
            if (is_string($startDate) && !empty($startDate)) {
                $queryParams = [
                    'startDate' => $startDate
                ];
            }
            else if ($startDate instanceof DateTime) {
                $queryParams = [
                    'startDate' => $startDate->format(DateTime::ISO8601)
                ];
            }
        }

        if ($endDate !== null) {
            if (is_string($endDate) && !empty($endDate)) {
                if ($queryParams === null) {
                    $queryParams = [];
                }

                $queryParams['endDate'] = $endDate;
            }
            else if ($endDate instanceof DateTime) {
                if ($queryParams === null) {
                    $queryParams = [];
                }

                $queryParams['endDate'] = $endDate->format(DateTime::ISO8601);
            }
        }

        return [
            'assets/:assetId/issuance', [
                'assetId' => $assetId
            ],
            $queryParams
        ];
    }

    /**
     * Set up request parameters for List Asset Holders API endpoint
     * @param $assetId
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listAssetHoldersRequestParams($assetId, $limit = null, $skip = null) {
        $queryParams = null;

        if ($limit !== null) {
            $queryParams = [
                'limit' => $limit
            ];
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
        }

        return [
            'assets/:assetId/holders', [
                'assetId' => $assetId
            ],
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
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendRequestAsync(RequestInterface $request) {
        return Promise\task(function () use (&$request) {
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
    }

    /**
     * Assembles the complete URL for an endpoint of a given type of service (either API or WS Notification)
     * @param int $serviceType
     * @param string $servicePath
     * @param array|null $urlParams
     * @param array|null $queryParams
     * @return UriInterface
     */
    private function assembleServiceEndPointUrl($serviceType, $servicePath, array $urlParams = null, array $queryParams = null) {
        $serviceEndPointUrl = new Uri(self::formatMethodPath($servicePath, $urlParams));

        if ($queryParams !== null) {
            foreach ($queryParams as $key => $value) {
                $serviceEndPointUrl = Uri::withQueryValue($serviceEndPointUrl, $key, $value);
            }
        }

        // Make sure that duplicate slashes that might occur in the URL (due to empty URL parameters)
        //  are reduced to a single slash so the URL used for signing is not different from the
        //  actual URL of the sent request
        $serviceEndPointUrl = UriNormalizer::normalize(
                UriResolver::resolve($serviceType === ServiceType::WS_NOTIFY ? $this->rootWsNtfyEndPoint : $this->rootApiEndPoint, $serviceEndPointUrl),
                UriNormalizer::REMOVE_DUPLICATE_SLASHES
        );

        return $serviceEndPointUrl;
    }

    /**
     * Assembles the complete URL for an API endpoint
     * @param string $methodPath
     * @param array|null $urlParams
     * @param array|null $queryParams
     * @return UriInterface
     */
    private function assembleMethodEndPointUrl($methodPath, array $urlParams = null, array $queryParams = null) {
        return $this->assembleServiceEndPointUrl(ServiceType::API, $methodPath, $urlParams, $queryParams);
    }

    /**
     * Assembles the complete URL for a WebServices Notify endpoint
     * @param $eventPath
     * @param array|null $urlParams
     * @return UriInterface
     */
    private function assembleWSNotifyEndPointUrl($eventPath, array $urlParams = null) {
        return $this->assembleServiceEndPointUrl(ServiceType::WS_NOTIFY, $eventPath, $urlParams);
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
     * @return PromiseInterface - A promise representing the asynchronous processing
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
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendPostRequestAsync($methodPath, stdClass $jsonData, array $urlParams = null, array $queryParams = null) {
        $request = new Request('POST', $this->assembleMethodEndPointUrl($methodPath, $urlParams, $queryParams),
                ['Content-Type' => 'application/json'], json_encode($jsonData));

        // Sign and send the request
        return $this->sendRequestAsync($request);
    }

    /**
     * Retrieves the HTTP request to be used to establish a WebServices channel for notification
     * @param string $eventName - Name of notification event
     * @return Request - Signed request
     * @throws Exception
     */
    protected function getWSNotifyRequest($eventName) {
        $request = new Request('GET', $this->assembleWSNotifyEndPointUrl(':eventName', ['eventName' => $eventName]));

        $this->signRequest($request);

        return $request;
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
                    // React event loop passed
                    $this->eventLoop = $optEventLoop;

                    // Set up specific HTTP client handler for processing asynchronous requests
                    $httpClientHandler = HandlerStack::create(new HttpClientAdapter($optEventLoop));

                    // Converts timeout for waiting indefinitely
                    if ($timeout == 0) {
                        $timeout = -1;
                    }

                    $pumpTaskQueue = true;

                    if (isset($options['pumpTaskQueue'])) {
                        $optPumpTaskQueue = $options['pumpTaskQueue'];

                        if (is_bool($optPumpTaskQueue)) {
                            $pumpTaskQueue = $optPumpTaskQueue;
                        }
                    }

                    if ($pumpTaskQueue) {
                        $queue = Promise\queue();
                        $optEventLoop->addPeriodicTimer(0, [$queue, 'run']);
                    }
                }
            }
        }

        $host = $subdomain . $hostName;
        $uriPrefix = ($secure ? 'https://' : 'http://') . $host;
        $apiBaseUriPath = self::$apiPath . $version . '/';
        $this->rootApiEndPoint = new Uri($uriPrefix . $apiBaseUriPath);
        $this->deviceId = $deviceId;
        $this->apiAccessSecret = $apiAccessSecret;
        $this->signValidPeriod = new DateInterval(sprintf('P%dD', self::$signValidDays));

        // Determine notification service version to use based on API version
        $apiVersion = new ApiVersion($version);

        $notifyServiceVer = $apiVersion->gte('0.6') ? '0.2' : '0.1';
        $notifyWSDispatcherVer = '0.1';

        $wsUriScheme = $secure ? 'wss://' : 'ws://';
        $wsUriPrefix = $wsUriScheme . $host;
        $qualifiedNotifyRooPath = self::$apiPath . self::$notifyRootPath;
        $wsNtfyBaseUriPath = $qualifiedNotifyRooPath . '/' . $notifyServiceVer . (!empty(self::$wsNtfyRootPath) ? '/' : '') . self::$wsNtfyRootPath . '/' . $notifyWSDispatcherVer . '/';
        $this->rootWsNtfyEndPoint = new Uri($wsUriPrefix . $wsNtfyBaseUriPath);

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
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'encoding' => [string],    (optional, default: 'utf8') One of the following values identifying the encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [boolean],    (optional, default: true) Indicates whether message should be encrypted before storing
     *      'storage' => [string]      (optional, default: 'auto') One of the following values identifying where the message should be stored: 'auto'|'embedded'|'external'
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
     *      'id' => [string],               ID of target device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supply ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @param string $message - The message to send
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
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
     * @param string|null $encoding - (optional, default: 'utf8') One of the following values identifying the encoding that should be used for the returned message: 'utf8'|'base64'|'hex'
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
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'action' => [string]              (optional, default: 'any') One of the following values specifying the action originally performed on
     *                                         the messages intended to be retrieved: 'log'|'send'|'any'
     *      'direction' => [string]           (optional, default: 'any') One of the following values specifying the direction of the sent messages
     *                                         intended to be retrieve: 'inbound'|'outbound'|'any'. Note that this option only applies to
     *                                         sent messages (action = 'send'). 'inbound' indicates messages sent to the device that issued
     *                                         the request, while 'outbound' indicates messages sent from the device that issued the request
     *      'fromDevices' => [array]          (optional) A list (simple array) of devices from which the messages intended to be retrieved had been sent.
     *                                         Note that this option only applies to messages sent to the device that issued the request (action = 'send' and direction = 'inbound')
     *          [n] => [array]                 Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]               ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]  (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *
     *      'toDevices' => [array]            (optional) A list (simple array) of devices to which the messages intended to be retrieved had been sent.
     *                                         Note that this option only applies to messages sent from the device that issued the request (action = 'send' and direction = 'outbound')
     *          [n] => [array]                 Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]               ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]  (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *
     *      'readState' => [string]           (optional, default: 'any') One of the following values indicating the current read state of the
     *                                         the messages intended to be retrieved: 'unread'|'read'|'any'.
     *      'startDate' => [string|DateTime]  (optional) Date and time specifying the lower boundary of the time frame within
     *                                         which the messages intended to be retrieved has been: logged, in case of messages logged
     *                                         by the device that issued the request (action = 'log'); sent, in case of messages sent from the current
     *                                         device (action = 'send' direction = 'outbound'); or received, in case of messages sent to
     *                                         the device that issued the request (action = 'send' and direction = 'inbound').
     *                                         Note: if a string is passed, assumes that it is an ISO8601 formatter date/time
     *      'endDate' => [string|DateTime]    (optional) Date and time specifying the upper boundary of the time frame within
     *                                         which the messages intended to be retrieved has been: logged, in case of messages logged
     *                                         by the device that issued the request (action = 'log'); sent, in case of messages sent from the current
     *                                         device (action = 'send' direction = 'outbound'); or received, in case of messages sent to
     *                                         the device that issued the request (action = 'send' and direction = 'inbound')
     *                                         Note: if a string is passed, assumes that it is an ISO8601 formatter date/time
     * @return stdClass - An object representing the JSON formatted data returned by the List Messages Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listMessages(array $options = null) {
        return $this->sendGetRequest(...self::listMessagesRequestParams($options));
    }

    /**
     * List permission events
     * @return stdClass - An object representing the JSON formatted data returned by the List Permission Events Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listPermissionEvents() {
        return $this->sendGetRequest(...self::listPermissionEventsRequestParams());
    }

    /**
     * Retrieve permission rights
     * @param string $eventName - Name of permission event
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Permission Rights Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function retrievePermissionRights($eventName) {
        return $this->sendGetRequest(...self::retrievePermissionRightsRequestParams($eventName));
    }

    /**
     * Set permission rights
     * @param string $eventName - Name of permission event
     * @param array $rights - A map (associative array) containing the following keys:
     *      'system' => [string]        (optional) Permission right to be attributed at system level for the specified event. Must be one of the following values: 'allow', 'deny'
     *      'catenisNode' => [array]    (optional) A map (associative array), specifying the permission rights to be attributed at the Catenis node level for the specified event,
     *                                   with the following keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of indices (or a single index) of Catenis nodes to be given allow right.
     *                                        Can optionally include the value 'self' to refer to the index of the Catenis node to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis nodes to be given deny right.
     *                                        Can optionally include the value 'self' to refer to the index of the Catenis node to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis nodes the rights of which should be removed.
     *                                        Can optionally include the value 'self' to refer to the index of the Catenis node to which the device belongs.
     *                                        The wildcard character ('*') can also be used to indicate that the rights for all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be attributed at the client level for the specified event,
     *                                   with the following keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of IDs (or a single ID) of clients to be given allow right.
     *                                        Can optionally include the value 'self' to refer to the ID of the client to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients to be given deny right.
     *                                        Can optionally include the value 'self' to refer to the ID of the client to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients the rights of which should be removed.
     *                                        Can optionally include the value 'self' to refer to the ID of the client to which the device belongs.
     *                                        The wildcard character ('*') can also be used to indicate that the rights for all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be attributed at the device level for the specified event,
     *                                   with the following keys:
     *          'allow' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given allow right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true.
     *                                                   Can optionally be replaced with value 'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *          'deny' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given deny right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *                                                   Can optionally be replaced with value 'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *          'none' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices the rights of which should be removed.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *                                                   Can optionally be replaced with value 'self' to refer to the ID of the device itself.
     *                                                   The wildcard character ('*') can also be used to indicate that the rights for all devices should be remove
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Set Permission Rights Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function setPermissionRights($eventName, $rights) {
        return $this->sendPostRequest(...self::setPermissionRightsRequestParams($eventName, $rights));
    }

    /**
     * Check effective permission right
     * @param string $eventName - Name of permission event
     * @param string $deviceId - ID of the device to check the permission right applied to it.
     *                            Can optionally be replaced with value 'self' to refer to the ID of the device that issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be interpreted as a product unique ID (otherwise, it is interpreted as a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Check Effective Permission Right Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function checkEffectivePermissionRight($eventName, $deviceId, $isProdUniqueId = false) {
        return $this->sendGetRequest(...self::checkEffectivePermissionRightRequestParams($eventName, $deviceId, $isProdUniqueId));
    }

    /**
     * List notification events
     * @return stdClass - An object representing the JSON formatted data returned by the List Notification Events Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listNotificationEvents() {
        return $this->sendGetRequest(...self::listNotificationEventsRequestParams());
    }

    /**
     * Retrieve device identification information
     * @param string $deviceId - ID of the device the identification information of which is to be retrieved.
     *                            Can optionally be replaced with value 'self' to refer to the ID of the device that issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be interpreted as a product unique ID (otherwise, it is interpreted as a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Device Identification Info Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function retrieveDeviceIdentificationInfo($deviceId, $isProdUniqueId = false) {
        return $this->sendGetRequest(...self::retrieveDeviceIdentificationInfoRequestParams($deviceId, $isProdUniqueId));
    }

    /**
     * Issue an amount of a new asset
     * @param array $assetInfo - A map (associative array), specifying the information for creating the new asset, with the following keys:
     *      'name' => [string]         The name of the asset
     *      'description' => [string]  (optional) The description of the asset
     *      'canReissue' => [bool]     Indicates whether more units of this asset can be issued at another time (an unlocked asset)
     *      'decimalPlaces' => [int]   The number of decimal places that can be used to specify a fractional amount of this asset
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative array), specifying the device for which the asset is issued
     *                                     and that shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of holding device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Issue Asset Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function issueAsset(array $assetInfo, $amount, array $holdingDevice = null) {
        return $this->sendPostRequest(...self::issueAssetRequestParams($assetInfo, $amount, $holdingDevice));
    }

    /**
     * Issue an additional amount of an existing asset
     * @param string $assetId - ID of asset to issue more units of it
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative array), specifying the device for which the asset is issued
     *                                     and that shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of holding device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Reissue Asset Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function reissueAsset($assetId, $amount, array $holdingDevice = null) {
        return $this->sendPostRequest(...self::reissueAssetRequestParams($assetId, $amount, $holdingDevice));
    }

    /**
     * Transfer an amount of an asset to a device
     * @param string $assetId - ID of asset to transfer
     * @param float $amount - Amount of asset to be transferred (expressed as a decimal amount)
     * @param array $receivingDevice - (optional, default: device that issues the request) A map (associative array), specifying the device Device to which the asset
     *                                  is to be transferred and that shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of receiving device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Transfer Asset Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function transferAsset($assetId, $amount, array $receivingDevice) {
        return $this->sendPostRequest(...self::transferAssetRequestParams($assetId, $amount, $receivingDevice));
    }

    /**
     * Retrieve information about a given asset
     * @param string $assetId - ID of asset to retrieve information
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Asset Info Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function retrieveAssetInfo($assetId) {
        return $this->sendGetRequest(...self::retrieveAssetInfoRequestParams($assetId));
    }

    /**
     * Get the current balance of a given asset held by the device
     * @param string $assetId - ID of asset to get balance
     * @return stdClass - An object representing the JSON formatted data returned by the Get Asset Balance Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function getAssetBalance($assetId) {
        return $this->sendGetRequest(...self::getAssetBalanceRequestParams($assetId));
    }

    /**
     * List assets owned by the device
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of list) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Owned Assets API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listOwnedAssets($limit = null, $skip = null) {
        return $this->sendGetRequest(...self::listOwnedAssetsRequestParams($limit, $skip));
    }

    /**
     * List assets issued by the device
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of list) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Issued Assets API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listIssuedAssets($limit = null, $skip = null) {
        return $this->sendGetRequest(...self::listIssuedAssetsRequestParams($limit, $skip));
    }

    /**
     * Retrieve issuance history for a given asset
     * @param string $assetId - ID of asset to retrieve issuance history
     * @param string|DateTime|null $startDate - (optional) Date and time specifying the lower boundary of the time frame within which the issuance events
     *                                           intended to be retrieved have occurred. The returned issuance events must have occurred not before that date/time.
     *                                           Note: if a string is passed, it should be an ISO8601 formatted date/time
     * @param string|DateTime|null $endDate - (optional) Date and time specifying the upper boundary of the time frame within which the issuance events
     *                                           intended to be retrieved have occurred. The returned issuance events must have occurred not after that date/time.
     *                                           Note: if a string is passed, it should be an ISO8601 formatted date/time
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Asset Issuance History API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function retrieveAssetIssuanceHistory($assetId, $startDate = null, $endDate = null) {
        return $this->sendGetRequest(...self::retrieveAssetIssuanceHistoryRequestParams($assetId, $startDate, $endDate));
    }

    /**
     * List devices that currently hold any amount of a given asset
     * @param string $assetId - ID of asset to get holders
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of list) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Asset Holders API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    function listAssetHolders($assetId, $limit = null, $skip = null) {
        return $this->sendGetRequest(...self::listAssetHoldersRequestParams($assetId, $limit, $skip));
    }

    /**
     * Create WebSocket Notification Channel for a given notification event
     * @param string $eventName - Name of Catenis notification event
     * @return WsNotifyChannel - Catenis notification channel object
     */
    function createWsNotifyChannel($eventName) {
        return new WsNotifyChannel($this, $eventName);
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
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function logMessageAsync($message, array $options = null) {
        return $this->sendPostRequestAsync(...self::logMessageRequestParams($message, $options));
    }

    /**
     * Send a message asynchronously
     * @param array $targetDevice - A map (associative array) containing the following keys:
     *      'id' => [string],               ID of target device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supply ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @param string $message - The message to send
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'readConfirmation' => [boolean], (optional, default: false) Indicates whether message should be sent with read confirmation enabled
     *      'encoding' => [string],          (optional, default: 'utf8') One of the following values identifying the encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [boolean],          (optional, default: true) Indicates whether message should be encrypted before storing
     *      'storage' => [string]            (optional, default: 'auto') One of the following values identifying where the message should be stored: 'auto'|'embedded'|'external'
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function sendMessageAsync(array $targetDevice, $message, array $options = null) {
        return $this->sendPostRequestAsync(...self::sendMessageRequestParams($targetDevice, $message, $options));
    }

    /**
     * Read a message asynchronously
     * @param string $messageId - The ID of the message to read
     * @param string|null $encoding - (default: 'utf8') One of the following values identifying the encoding that should be used for the returned message: 'utf8'|'base64'|'hex'
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function readMessageAsync($messageId, $encoding = null) {
        return $this->sendGetRequestAsync(...self::readMessageRequestParams($messageId, $encoding));
    }

    /**
     * Retrieve message container asynchronously
     * @param string $messageId - The ID of message to retrieve container info
     * @return PromiseInterface - A promise representing the asynchronous processing
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
     *              'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *
     *      'toDevices' => [array]                (optional) - A list (simple array) of devices to which the messages intended to be retrieved had been sent.
     *                                             Note that this option only applies to messages sent from the device that issued the request (action = 'send' and direction = 'outbound')
     *          [n] => [array]                     Each item of the list is a map (associative array) containing the following keys:
     *              'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
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
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function listMessagesAsync(array $options = null) {
        return $this->sendGetRequestAsync(...self::listMessagesRequestParams($options));
    }

    /**
     * List permission events asynchronously
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function listPermissionEventsAsync() {
        return $this->sendGetRequestAsync(...self::listPermissionEventsRequestParams());
    }

    /**
     * Retrieve permission rights asynchronously
     * @param string $eventName - Name of permission event
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function retrievePermissionRightsAsync($eventName) {
        return $this->sendGetRequestAsync(...self::retrievePermissionRightsRequestParams($eventName));
    }

    /**
     * Set permission rights asynchronously
     * @param string $eventName - Name of permission event
     * @param array $rights - A map (associative array) containing the following keys:
     *      'system' => [string]        (optional) Permission right to be attributed at system level for the specified event. Must be one of the following values: 'allow', 'deny'
     *      'catenisNode' => [array]    (optional) A map (associative array), specifying the permission rights to be attributed at the Catenis node level for the specified event,
     *                                   with the following keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of indices (or a single index) of Catenis nodes to be given allow right.
     *                                        Can optionally include the value 'self' to refer to the index of the Catenis node to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis nodes to be given deny right.
     *                                        Can optionally include the value 'self' to refer to the index of the Catenis node to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis nodes the rights of which should be removed.
     *                                        Can optionally include the value 'self' to refer to the index of the Catenis node to which the device belongs.
     *                                        The wildcard character ('*') can also be used to indicate that the rights for all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be attributed at the client level for the specified event,
     *                                   with the following keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of IDs (or a single ID) of clients to be given allow right.
     *                                        Can optionally include the value 'self' to refer to the ID of the client to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients to be given deny right.
     *                                        Can optionally include the value 'self' to refer to the ID of the client to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients the rights of which should be removed.
     *                                        Can optionally include the value 'self' to refer to the ID of the client to which the device belongs.
     *                                        The wildcard character ('*') can also be used to indicate that the rights for all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be attributed at the device level for the specified event,
     *                                   with the following keys:
     *          'allow' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given allow right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true.
     *                                                   Can optionally be replaced with value 'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *          'deny' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given deny right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *                                                   Can optionally be replaced with value 'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     *          'none' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices the rights of which should be removed.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless isProdUniqueId is true
     *                                                   Can optionally be replaced with value 'self' to refer to the ID of the device itself.
     *                                                   The wildcard character ('*') can also be used to indicate that the rights for all devices should be remove
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function setPermissionRightsAsync($eventName, $rights) {
        return $this->sendPostRequestAsync(...self::setPermissionRightsRequestParams($eventName, $rights));
    }

    /**
     * Check effective permission right asynchronously
     * @param string $eventName - Name of permission event
     * @param string $deviceId - ID of the device to check the permission right applied to it.
     *                            Can optionally be replaced with value 'self' to refer to the ID of the device that issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be interpreted as a product unique ID (otherwise, it is interpreted as a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function checkEffectivePermissionRightAsync($eventName, $deviceId, $isProdUniqueId = false) {
        return $this->sendGetRequestAsync(...self::checkEffectivePermissionRightRequestParams($eventName, $deviceId, $isProdUniqueId));
    }

    /**
     * List notification events asynchronously
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function listNotificationEventsAsync() {
        return $this->sendGetRequestAsync(...self::listNotificationEventsRequestParams());
    }

    /**
     * Retrieve device identification information asynchronously
     * @param string $deviceId - ID of the device the identification information of which is to be retrieved.
     *                            Can optionally be replaced with value 'self' to refer to the ID of the device that issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be interpreted as a product unique ID (otherwise, it is interpreted as a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function retrieveDeviceIdentificationInfoAsync($deviceId, $isProdUniqueId = false) {
        return $this->sendGetRequestAsync(...self::retrieveDeviceIdentificationInfoRequestParams($deviceId, $isProdUniqueId));
    }

    /**
     * Issue an amount of a new asset asynchronously
     * @param array $assetInfo - A map (associative array), specifying the information for creating the new asset, with the following keys:
     *      'name' => [string]         The name of the asset
     *      'description' => [string]  (optional) The description of the asset
     *      'canReissue' => [bool]     Indicates whether more units of this asset can be issued at another time (an unlocked asset)
     *      'decimalPlaces' => [int]   The number of decimal places that can be used to specify a fractional amount of this asset
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative array), specifying the device for which the asset is issued
     *                                     and that shall hold the total issued amount, with the following keys:
     *      'id' => [string]                ID of holding device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function issueAssetAsync(array $assetInfo, $amount, array $holdingDevice = null) {
        return $this->sendPostRequestAsync(...self::issueAssetRequestParams($assetInfo, $amount, $holdingDevice));
    }

    /**
     * Issue an additional amount of an existing asset asynchronously
     * @param string $assetId - ID of asset to issue more units of it
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative array), specifying the device for which the asset is issued
     *                                     and that shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of holding device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function reissueAssetAsync($assetId, $amount, array $holdingDevice = null) {
        return $this->sendPostRequestAsync(...self::reissueAssetRequestParams($assetId, $amount, $holdingDevice));
    }

    /**
     * Transfer an amount of an asset to a device asynchronously
     * @param string $assetId - ID of asset to transfer
     * @param float $amount - Amount of asset to be transferred (expressed as a decimal amount)
     * @param array $receivingDevice - (optional, default: device that issues the request) A map (associative array), specifying the device Device to which the asset
     *                                  is to be transferred and that shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of receiving device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function transferAssetAsync($assetId, $amount, array $receivingDevice) {
        return $this->sendPostRequestAsync(...self::transferAssetRequestParams($assetId, $amount, $receivingDevice));
    }

    /**
     * Retrieve information about a given asset asynchronously
     * @param string $assetId - ID of asset to retrieve information
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function retrieveAssetInfoAsync($assetId) {
        return $this->sendGetRequestAsync(...self::retrieveAssetInfoRequestParams($assetId));
    }

    /**
     * Get the current balance of a given asset held by the device asynchronously
     * @param string $assetId - ID of asset to get balance
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function getAssetBalanceAsync($assetId) {
        return $this->sendGetRequestAsync(...self::getAssetBalanceRequestParams($assetId));
    }

    /**
     * List assets owned by the device asynchronously
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of list) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function listOwnedAssetsAsync($limit = null, $skip = null) {
        return $this->sendGetRequestAsync(...self::listOwnedAssetsRequestParams($limit, $skip));
    }

    /**
     * List assets issued by the device asynchronously
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of list) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function listIssuedAssetsAsync($limit = null, $skip = null) {
        return $this->sendGetRequestAsync(...self::listIssuedAssetsRequestParams($limit, $skip));
    }

    /**
     * Retrieve issuance history for a given asset asynchronously
     * @param string $assetId - ID of asset to retrieve issuance history
     * @param string|DateTime|null $startDate - (optional) Date and time specifying the lower boundary of the time frame within which the issuance events
     *                                           intended to be retrieved have occurred. The returned issuance events must have occurred not before that date/time.
     *                                           Note: if a string is passed, it should be an ISO8601 formatted date/time
     * @param string|DateTime|null $endDate - (optional) Date and time specifying the upper boundary of the time frame within which the issuance events
     *                                           intended to be retrieved have occurred. The returned issuance events must have occurred not after that date/time.
     *                                           Note: if a string is passed, it should be an ISO8601 formatted date/time
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function retrieveAssetIssuanceHistoryAsync($assetId, $startDate = null, $endDate = null) {
        return $this->sendGetRequestAsync(...self::retrieveAssetIssuanceHistoryRequestParams($assetId, $startDate, $endDate));
    }

    /**
     * List devices that currently hold any amount of a given asset asynchronously
     * @param string $assetId - ID of asset to get holders
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of list) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    function listAssetHoldersAsync($assetId, $limit = null, $skip = null) {
        return $this->sendGetRequestAsync(...self::listAssetHoldersRequestParams($assetId, $limit, $skip));
    }
}