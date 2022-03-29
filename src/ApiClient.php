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
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\UriNormalizer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use WyriHaximus\React\GuzzlePsr7\HttpClientAdapter;
use Catenis\Notification\WsNotifyChannel;
use Catenis\Internal\ServiceType;
use Catenis\Internal\ApiPackage;
use Catenis\Exception\CatenisException;
use Catenis\Exception\CatenisClientException;
use Catenis\Exception\CatenisApiException;

class ApiClient extends ApiPackage
{
    private static $apiPath = '/api/';
    private static $signVersionId = 'CTN1';
    private static $signMethodId = 'CTN1-HMAC-SHA256';
    private static $scopeRequest = 'ctn1_request';
    private static $signValidDays = 7;
    private static $notifyRootPath = 'notify';
    private static $wsNtfyRootPath =  'ws';
    private static $timestampHdr = 'X-BCoT-Timestamp';
    private static $notifyWsSubprotocol = 'notify.catenis.io';
    private static $notifyChannelOpenMsg = 'NOTIFICATION_CHANNEL_OPEN';

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
    protected function getTimestampHeader()
    {
        return self::$timestampHdr;
    }

    /**
     * @return string
     */
    protected function getNotifyWsSubprotocol()
    {
        return self::$notifyWsSubprotocol;
    }

    /**
     * @return string
     */
    protected function getNotifyChannelOpenMsg()
    {
        return self::$notifyChannelOpenMsg;
    }

    /**
     * @param string $methodPath - The URI path of the API endpoint
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of the url parameters
     *      that are to be substituted and the values the values to be used for the substitution
     * @return mixed - The formatted URI path
     */
    private static function formatMethodPath(&$methodPath, array $urlParams = null)
    {
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
    private static function hashData($data)
    {
        return hash('sha256', $data);
    }

    /**
     * Signs a byte sequence with a given secret key
     * @param string $data - The data to be signed
     * @param string $secret - The key to be used for signing
     * @param bool $hexEncode - Indicates whether the output should be hex encoded
     * @return string - The generated signature
     */
    private static function signData($data, $secret, $hexEncode = false)
    {
        return hash_hmac('sha256', $data, $secret, !$hexEncode);
    }

    /**
     * Process response from HTTP request
     * @param ResponseInterface $response - The HTTP response
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private static function processResponse(ResponseInterface $response)
    {
        // Process response
        $body = (string)$response->getBody();
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            // Error returned from API endpoint. Retrieve Catenis API error message if returned
            $ctnErrorMessage = null;

            if (!empty($body)) {
                $jsonBody = json_decode($body);

                if ($jsonBody !== null && is_object($jsonBody) && isset($jsonBody->status)
                        && isset($jsonBody->message)) {
                    $ctnErrorMessage = $jsonBody->message;
                }
            }

            // Throw API response exception
            throw new CatenisApiException($response->getReasonPhrase(), $statusCode, $ctnErrorMessage);
        }

        // Validate and return data returned as response
        if (!empty($body)) {
            $jsonBody = json_decode($body);

            if ($jsonBody !== null && is_object($jsonBody) && isset($jsonBody->status)
                    && $jsonBody->status === 'success' && isset($jsonBody->data)) {
                // Return the data
                return $jsonBody->data;
            }
        }

        // Invalid data returned. Throw exception
        throw new CatenisClientException("Unexpected response returned by API endpoint: $body");
    }

    /**
     * Given an associative array, returns a copy of that array excluding keys whose value is null
     * @param array $map
     * @return array
     */
    private static function filterNonNullKeys(array $map)
    {
        if (is_array($map)) {
            $filteredMap = [];

            foreach ($map as $key => $value) {
                if (!is_null($value)) {
                    $filteredMap[$key] = $value;
                }
            }

            $map = $filteredMap;
        }

        return $map;
    }

    /**
     * Set up request parameters for Log Message API endpoint
     * @param string|array $message
     * @param array|null $options
     * @return array
     */
    private static function logMessageRequestParams($message, array $options = null)
    {
        $jsonData = new stdClass();

        $jsonData->message = $message;

        if ($options !== null) {
            $filteredOptions = self::filterNonNullKeys($options);

            if (!empty($filteredOptions)) {
                $jsonData->options = $filteredOptions;
            }
        }

        return [
            'messages/log',
            $jsonData
        ];
    }

    /**
     * Set up request parameters for Send Message API endpoint
     * @param string|array $message
     * @param array $targetDevice
     * @param array|null $options
     * @return array
     */
    private static function sendMessageRequestParams($message, array $targetDevice, array $options = null)
    {
        $jsonData = new stdClass();

        $jsonData->message = $message;
        $jsonData->targetDevice = $targetDevice;

        if ($options !== null) {
            $filteredOptions = self::filterNonNullKeys($options);

            if (!empty($filteredOptions)) {
                $jsonData->options = $filteredOptions;
            }
        }

        return [
            'messages/send',
            $jsonData
        ];
    }

    /**
     * Set up request parameters for Read Message API endpoint
     * @param string $messageId
     * @param string|array|null $options
     * @return array
     */
    private static function readMessageRequestParams($messageId, $options = null)
    {
        $queryParams = null;

        if (is_string($options)) {
            $queryParams = [
                'encoding' => $options
            ];
        } elseif (is_array($options)) {
            $filteredOptions = self::filterNonNullKeys($options);

            if (!empty($filteredOptions)) {
                $queryParams = $filteredOptions;
            }
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
    private static function retrieveMessageContainerRequestParams($messageId)
    {
        return [
            'messages/:messageId/container', [
                'messageId' => $messageId
            ]
        ];
    }

    /**
     * Set up request parameters for Retrieve Message Origin API endpoint
     * @param string $messageId
     * @param string|null $msgToSign
     * @return array
     */
    private static function retrieveMessageOriginRequestParams($messageId, $msgToSign = null)
    {
        $queryParams = null;

        if ($msgToSign !== null) {
            $queryParams = [
                'msgToSign' => $msgToSign
            ];
        }

        return [
            'messages/:messageId/origin', [
                'messageId' => $messageId
            ],
            $queryParams,
            true
        ];
    }

    /**
     * Set up request parameters for Retrieve Message Progress API endpoint
     * @param string $messageId
     * @return array
     */
    private static function retrieveMessageProgressRequestParams($messageId)
    {
        return [
            'messages/:messageId/progress', [
                'messageId' => $messageId
            ]
        ];
    }

    /**
     * Set up request parameters for List Messages API endpoint
     * @param array|null $selector
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listMessagesRequestParams(array $selector = null, $limit = null, $skip = null)
    {
        $queryParams = null;

        if ($selector !== null) {
            $queryParams = [];

            if (isset($selector['action'])) {
                $queryParams['action'] = $selector['action'];
            }

            if (isset($selector['direction'])) {
                $queryParams['direction'] = $selector['direction'];
            }

            if (isset($selector['fromDevices'])) {
                // Process from devices list
                $fromDevices = $selector['fromDevices'];

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
                                } else {
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

            if (isset($selector['toDevices'])) {
                // Process to devices list
                $toDevices = $selector['toDevices'];

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
                                } else {
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

            if (isset($selector['readState'])) {
                $queryParams['readState'] = $selector['readState'];
            }

            if (isset($selector['startDate'])) {
                $startDate = $selector['startDate'];

                if (is_string($startDate) && !empty($startDate)) {
                    $queryParams['startDate'] = $startDate;
                } elseif ($startDate instanceof DateTime) {
                    $queryParams['startDate'] = $startDate->format(DateTime::ISO8601);
                }
            }

            if (isset($selector['endDate'])) {
                $endDate = $selector['endDate'];

                if (is_string($endDate) && !empty($endDate)) {
                    $queryParams['endDate'] = $endDate;
                } elseif ($endDate instanceof DateTime) {
                    $queryParams['endDate'] = $endDate->format(DateTime::ISO8601);
                }
            }
        }

        if ($limit !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['limit'] = $limit;
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
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
    private static function listPermissionEventsRequestParams()
    {
        return [
            'permission/events'
        ];
    }

    /**
     * Set up request parameters for Retrieve Permission Rights API endpoint
     * @param string $eventName
     * @return array
     */
    private static function retrievePermissionRightsRequestParams($eventName)
    {
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
    private static function setPermissionRightsRequestParams($eventName, array $rights)
    {
        return [
            'permission/events/:eventName/rights',
            (object)$rights, [
                'eventName' => $eventName
            ]
        ];
    }

    /**
     * Set up request parameters for Check Effective Permission Right API endpoint
     * @param string $eventName
     * @param string $deviceId
     * @param bool $isProdUniqueId
     * @return array
     */
    private static function checkEffectivePermissionRightRequestParams($eventName, $deviceId, $isProdUniqueId = false)
    {
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
    private static function listNotificationEventsRequestParams()
    {
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
    private static function retrieveDeviceIdentificationInfoRequestParams($deviceId, $isProdUniqueId = false)
    {
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
    private static function issueAssetRequestParams(array $assetInfo, $amount, array $holdingDevice = null)
    {
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
    private static function reissueAssetRequestParams($assetId, $amount, array $holdingDevice = null)
    {
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
    private static function transferAssetRequestParams($assetId, $amount, array $receivingDevice)
    {
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
    private static function retrieveAssetInfoRequestParams($assetId)
    {
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
    private static function getAssetBalanceRequestParams($assetId)
    {
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
    private static function listOwnedAssetsRequestParams($limit = null, $skip = null)
    {
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
    private static function listIssuedAssetsRequestParams($limit = null, $skip = null)
    {
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
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function retrieveAssetIssuanceHistoryRequestParams(
        $assetId,
        $startDate = null,
        $endDate = null,
        $limit = null,
        $skip = null
    ) {
        $queryParams = null;

        if ($startDate !== null) {
            if (is_string($startDate) && !empty($startDate)) {
                $queryParams = [
                    'startDate' => $startDate
                ];
            } elseif ($startDate instanceof DateTime) {
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
            } elseif ($endDate instanceof DateTime) {
                if ($queryParams === null) {
                    $queryParams = [];
                }

                $queryParams['endDate'] = $endDate->format(DateTime::ISO8601);
            }
        }

        if ($limit !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['limit'] = $limit;
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
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
     * @param string $assetId
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listAssetHoldersRequestParams($assetId, $limit = null, $skip = null)
    {
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
     * Set up request parameters for Export Asset API endpoint
     *
     * @param string $assetId
     * @param string $foreignBlockchain
     * @param array $token
     * @param array|null $options
     * @return array
     */
    private static function exportAssetRequestParams($assetId, $foreignBlockchain, array $token, $options = null)
    {
        $jsonData = new stdClass();

        $jsonData->token = $token;

        if (is_array($options)) {
            $filteredOptions = self::filterNonNullKeys($options);

            if (!empty($filteredOptions)) {
                $jsonData->options = $filteredOptions;
            }
        }

        return [
            'assets/:assetId/export/:foreignBlockchain',
            $jsonData, [
                'assetId' => $assetId,
                'foreignBlockchain' => $foreignBlockchain
            ],
        ];
    }

    /**
     * Set up request parameters for Migrate Asset API endpoint
     *
     * @param string $assetId
     * @param string $foreignBlockchain
     * @param array|string $migration
     * @param array|null $options
     * @return array
     */
    private static function migrateAssetRequestParams($assetId, $foreignBlockchain, $migration, $options = null)
    {
        $jsonData = new stdClass();

        if (is_array($migration)) {
            $jsonData->migration = self::filterNonNullKeys($migration);
        } else {
            $jsonData->migration = $migration;
        }

        if (is_array($options)) {
            $filteredOptions = self::filterNonNullKeys($options);

            if (!empty($filteredOptions)) {
                $jsonData->options = $filteredOptions;
            }
        }

        return [
            'assets/:assetId/migrate/:foreignBlockchain',
            $jsonData, [
                'assetId' => $assetId,
                'foreignBlockchain' => $foreignBlockchain
            ],
        ];
    }

    /**
     * Set up request parameters for Asset Export Outcome API endpoint
     *
     * @param string $assetId
     * @param string $foreignBlockchain
     * @return array
     */
    private static function assetExportOutcomeRequestParams($assetId, $foreignBlockchain)
    {
        return [
            'assets/:assetId/export/:foreignBlockchain', [
                'assetId' => $assetId,
                'foreignBlockchain' => $foreignBlockchain
            ]
        ];
    }

    /**
     * Set up request parameters for Asset Migration Outcome API endpoint
     *
     * @param string $migrationId
     * @return array
     */
    private static function assetMigrationOutcomeRequestParams($migrationId)
    {
        return [
            'assets/migrations/:migrationId', [
                'migrationId' => $migrationId
            ]
        ];
    }

    /**
     * Set up request parameters for List Exported Assets API endpoint
     *
     * @param array|null $selector
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listExportedAssetsRequestParams($selector = null, $limit = null, $skip = null)
    {
        $queryParams = null;

        if ($selector !== null) {
            $queryParams = [];

            if (isset($selector['assetId'])) {
                $queryParams['assetId'] = $selector['assetId'];
            }

            if (isset($selector['foreignBlockchain'])) {
                $queryParams['foreignBlockchain'] = $selector['foreignBlockchain'];
            }

            if (isset($selector['tokenSymbol'])) {
                $queryParams['tokenSymbol'] = $selector['tokenSymbol'];
            }

            if (isset($selector['status'])) {
                $queryParams['status'] = $selector['status'];
            }

            if (isset($selector['negateStatus'])) {
                $queryParams['negateStatus'] = $selector['negateStatus'];
            }

            if (isset($selector['startDate'])) {
                $startDate = $selector['startDate'];

                if (is_string($startDate) && !empty($startDate)) {
                    $queryParams['startDate'] = $startDate;
                } elseif ($startDate instanceof DateTime) {
                    $queryParams['startDate'] = $startDate->format(DateTime::ISO8601);
                }
            }

            if (isset($selector['endDate'])) {
                $endDate = $selector['endDate'];

                if (is_string($endDate) && !empty($endDate)) {
                    $queryParams['endDate'] = $endDate;
                } elseif ($endDate instanceof DateTime) {
                    $queryParams['endDate'] = $endDate->format(DateTime::ISO8601);
                }
            }
        }

        if ($limit !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['limit'] = $limit;
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
        }

        return [
            'assets/exported',
            null,
            $queryParams
        ];
    }

    /**
     * Set up request parameters for List Asset Migrations API endpoint
     *
     * @param array|null $selector
     * @param int|null $limit
     * @param int|null $skip
     * @return array
     */
    private static function listAssetMigrationsRequestParams($selector = null, $limit = null, $skip = null)
    {
        $queryParams = null;

        if ($selector !== null) {
            $queryParams = [];

            if (isset($selector['assetId'])) {
                $queryParams['assetId'] = $selector['assetId'];
            }

            if (isset($selector['foreignBlockchain'])) {
                $queryParams['foreignBlockchain'] = $selector['foreignBlockchain'];
            }

            if (isset($selector['direction'])) {
                $queryParams['direction'] = $selector['direction'];
            }

            if (isset($selector['status'])) {
                $queryParams['status'] = $selector['status'];
            }

            if (isset($selector['negateStatus'])) {
                $queryParams['negateStatus'] = $selector['negateStatus'];
            }

            if (isset($selector['startDate'])) {
                $startDate = $selector['startDate'];

                if (is_string($startDate) && !empty($startDate)) {
                    $queryParams['startDate'] = $startDate;
                } elseif ($startDate instanceof DateTime) {
                    $queryParams['startDate'] = $startDate->format(DateTime::ISO8601);
                }
            }

            if (isset($selector['endDate'])) {
                $endDate = $selector['endDate'];

                if (is_string($endDate) && !empty($endDate)) {
                    $queryParams['endDate'] = $endDate;
                } elseif ($endDate instanceof DateTime) {
                    $queryParams['endDate'] = $endDate->format(DateTime::ISO8601);
                }
            }
        }

        if ($limit !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['limit'] = $limit;
        }

        if ($skip !== null) {
            if ($queryParams === null) {
                $queryParams = [];
            }

            $queryParams['skip'] = $skip;
        }

        return [
            'assets/migrations',
            null,
            $queryParams
        ];
    }

    /**
     * Signs an HTTP request to an API endpoint adding the proper HTTP headers
     * @param RequestInterface &$request - The request to be signed
     * @throws Exception
     */
    private function signRequest(RequestInterface &$request)
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $timeStamp = $now->format('Ymd\THis\Z');

        if ($this->lastSignDate !== null && (clone $this->lastSignDate)->add($this->signValidPeriod) > $now) {
            $useSameSignKey = $this->lastSignKey !== null;
        } else {
            $this->lastSignDate = $now;
            $useSameSignKey = false;
        }

        $signDate = $this->lastSignDate->format('Ymd');

        $request = $request->withHeader(self::$timestampHdr, $timeStamp);

        // First step: compute conformed request
        $confReq = $request->getMethod() . PHP_EOL;
        $confReq .= $request->getRequestTarget() . PHP_EOL;

        $essentialHeaders = 'host:' . $request->getHeaderLine('Host') . PHP_EOL;
        $essentialHeaders .= strtolower(self::$timestampHdr) . ':' . $request->getHeaderLine(self::$timestampHdr)
            . PHP_EOL;

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
        } else {
            $dateKey = self::signData($signDate, self::$signVersionId . $this->apiAccessSecret);
            $signKey = $this->lastSignKey = self::signData(self::$scopeRequest, $dateKey);
        }

        $credential = $this->deviceId . '/' . $scope;
        $signature = self::signData($strToSign, $signKey, true);

        // Step four: add authorization header
        $request = $request->withHeader('Authorization', self::$signMethodId . ' Credential=' . $credential
            . ', Signature=' . $signature);
    }

    /**
     * Sends a request to an API endpoint
     * @param RequestInterface $request - The request to send
     * @param boolean $doNotSign Indicates whether request should not be signed
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private function sendRequest(RequestInterface $request, $doNotSign = false)
    {
        try {
            if (!$doNotSign) {
                // Sign request
                $this->signRequest($request);
            }

            // Send request
            $response = $this->httpClient->send($request);

            // Process response
            return self::processResponse($response);
        } catch (CatenisException $apiEx) {
            // Just re-throws exception
            throw $apiEx;
        } catch (Exception $ex) {
            // Exception processing request. Throws local exception
            throw new CatenisClientException(null, $ex);
        }
    }

    /**
     * Sends a request to an API endpoint asynchronously
     * @param RequestInterface $request - The request to send
     * @param boolean $doNotSign Indicates whether request should not be signed
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendRequestAsync(RequestInterface $request, $doNotSign = false)
    {
        return Promise\task(function () use (&$request, $doNotSign) {
            try {
                if (!$doNotSign) {
                    // Sign request
                    $this->signRequest($request);
                }

                // Send request
                return $this->httpClient->sendAsync($request)->then(
                    function (ResponseInterface $response) {
                        // Process response
                        return self::processResponse($response);
                    },
                    function (Exception $ex) {
                        // Exception while sending request. Re-throw local exception
                        throw new CatenisClientException(null, $ex);
                    }
                );
            } catch (Exception $ex) {
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
    private function assembleServiceEndPointUrl(
        $serviceType,
        $servicePath,
        array $urlParams = null,
        array $queryParams = null
    ) {
        $serviceEndPointUrl = new Uri(self::formatMethodPath($servicePath, $urlParams));

        if ($queryParams !== null) {
            foreach ($queryParams as $key => $value) {
                // Make sure that false boolean values are shown on the query string (otherwise they get converted
                //  to an empty string and no value is shown but only the key followed by an equal sign)
                if (is_bool($value) && !$value) {
                    $value = 0;
                }

                $serviceEndPointUrl = Uri::withQueryValue($serviceEndPointUrl, $key, $value);
            }

            $query = $serviceEndPointUrl->getQuery();

            if (strpos($query, '+') !== false) {
                // Escape any '+' character found in query string so that it does not get converted to a blank
                //  space by the Catenis server
                $serviceEndPointUrl = $serviceEndPointUrl->withQuery(
                    strtr($query, ['+' => '%2B'])
                );
            }
        }

        // Make sure that duplicate slashes that might occur in the URL (due to empty URL parameters)
        //  are reduced to a single slash so the URL used for signing is not different from the
        //  actual URL of the sent request
        $serviceEndPointUrl = UriNormalizer::normalize(
            UriResolver::resolve(
                $serviceType === ServiceType::WS_NOTIFY ? $this->rootWsNtfyEndPoint : $this->rootApiEndPoint,
                $serviceEndPointUrl
            ),
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
    private function assembleMethodEndPointUrl($methodPath, array $urlParams = null, array $queryParams = null)
    {
        return $this->assembleServiceEndPointUrl(ServiceType::API, $methodPath, $urlParams, $queryParams);
    }

    /**
     * Assembles the complete URL for a WebServices Notify endpoint
     * @param $eventPath
     * @param array|null $urlParams
     * @return UriInterface
     */
    private function assembleWSNotifyEndPointUrl($eventPath, array $urlParams = null)
    {
        return $this->assembleServiceEndPointUrl(ServiceType::WS_NOTIFY, $eventPath, $urlParams);
    }

    /**
     * Sends a GET request to a given API endpoint
     * @param $methodPath - The (relative) path to the API endpoint
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @param boolean $doNotSign Indicates whether request should not be signed
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private function sendGetRequest($methodPath, array $urlParams = null, array $queryParams = null, $doNotSign = false)
    {
        // Prepare request
        $headers = [];

        if ($this->useCompression) {
            $headers['Accept-Encoding'] = 'deflate';
        }

        $request = new Request(
            'GET',
            $this->assembleMethodEndPointUrl(
                $methodPath,
                $urlParams,
                $queryParams
            ),
            $headers
        );

        // Sign and send the request
        return $this->sendRequest($request, $doNotSign);
    }

    /**
     * Sends a GET request to a given API endpoint asynchronously
     * @param $methodPath - The (relative) path to the API endpoint
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @param boolean $doNotSign Indicates whether request should not be signed
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendGetRequestAsync(
        $methodPath,
        array $urlParams = null,
        array $queryParams = null,
        $doNotSign = false
    ) {
        return Promise\task(function () use (&$methodPath, &$urlParams, &$queryParams, $doNotSign) {
            // Prepare request
            $headers = [];

            if ($this->useCompression) {
                $headers['Accept-Encoding'] = 'deflate';
            }

            $request = new Request(
                'GET',
                $this->assembleMethodEndPointUrl(
                    $methodPath,
                    $urlParams,
                    $queryParams
                ),
                $headers
            );

            // Sign and send the request asynchronously
            return $this->sendRequestAsync($request, $doNotSign);
        });
    }

    /**
     * Sends a GET request to a given API endpoint
     * @param $methodPath - The (relative) path to the API endpoint
     * @param stdClass $jsonData - An object representing the JSON formatted data is to be sent with the request
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @param boolean $doNotSign Indicates whether request should not be signed
     * @return stdClass - An object representing the JSON formatted data returned by the API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    private function sendPostRequest(
        $methodPath,
        stdClass $jsonData,
        array $urlParams = null,
        array $queryParams = null,
        $doNotSign = false
    ) {
        // Prepare request
        $headers = ['Content-Type' => 'application/json'];
        $body = json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->useCompression) {
            $headers['Accept-Encoding'] = 'deflate';

            if (extension_loaded('zlib') && strlen($body) >= $this->compressThreshold) {
                $headers['Content-Encoding'] = 'deflate';

                $body = gzencode($body, -1, FORCE_DEFLATE);
            }
        }

        $request = new Request(
            'POST',
            $this->assembleMethodEndPointUrl(
                $methodPath,
                $urlParams,
                $queryParams
            ),
            $headers,
            $body
        );

        // Sign and send the request
        return $this->sendRequest($request, $doNotSign);
    }

    /**
     * Sends a GET request to a given API endpoint asynchronously
     * @param $methodPath - The (relative) path to the API endpoint
     * @param stdClass $jsonData - An object representing the JSON formatted data is to be sent with the request
     * @param array|null $urlParams - A map (associative array) the keys of which are the names of url parameters
     *      that should be substituted and the values the values that should be used for the substitution
     * @param array|null $queryParams - A map (associative array) the keys of which are the names of query string
     *      parameters that should be added to the URL and the values the corresponding values of those parameters
     * @param boolean $doNotSign Indicates whether request should not be signed
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    private function sendPostRequestAsync(
        $methodPath,
        stdClass $jsonData,
        array $urlParams = null,
        array $queryParams = null,
        $doNotSign = false
    ) {
        return Promise\task(function () use (&$methodPath, &$jsonData, &$urlParams, &$queryParams, $doNotSign) {
            // Prepare request
            $headers = ['Content-Type' => 'application/json'];
            $body = json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($this->useCompression) {
                $headers['Accept-Encoding'] = 'deflate';

                if (extension_loaded('zlib') && strlen($body) >= $this->compressThreshold) {
                    $headers['Content-Encoding'] = 'deflate';
        
                    $body = gzencode($body, -1, FORCE_DEFLATE);
                }
            }

            $request = new Request(
                'POST',
                $this->assembleMethodEndPointUrl(
                    $methodPath,
                    $urlParams,
                    $queryParams
                ),
                $headers,
                $body
            );

            // Sign and send the request
            return $this->sendRequestAsync($request, $doNotSign);
        });
    }

    /**
     * Retrieves the HTTP request to be used to establish a WebServices channel for notification
     * @param string $eventName - Name of notification event
     * @return Request - Signed request
     * @throws Exception
     */
    protected function getWSNotifyRequest($eventName)
    {
        $request = new Request('GET', $this->assembleWSNotifyEndPointUrl(':eventName', ['eventName' => $eventName]));

        $this->signRequest($request);

        return $request;
    }

    /**
     * ApiClient constructor.
     * @param string|null $deviceId
     * @param string|null $apiAccessSecret
     * @param array|null $options - A map (associative array) containing the following keys:
     *      'host' => [string]           - (optional, default: 'catenis.io') Host name (with optional port) of
     *                                      target Catenis API server
     *      'environment' => [string]    - (optional, default: 'prod') Environment of target Catenis API server.
     *                                      Valid values: 'prod', 'sandbox' (or 'beta')
     *      'secure' => [bool]           - (optional, default: true) Indicates whether a secure connection (HTTPS)
     *                                      should be used
     *      'version' => [string]        - (optional, default: '0.11') Version of Catenis API to target
     *      'useCompression' => [bool]   - (optional, default: true) Indicates whether request/response body should
     *                                      be compressed
     *      'compressThreshold' => [int] - (optional, default: 1024) Minimum size, in bytes, of request body for it
     *                                      to be compressed
     *      'timeout' => [float|int]     - (optional, default: 0, no timeout) Timeout, in seconds, to wait for a
     *                                      response
     *      'eventLoop' => [EventLoop\LoopInterface] - (optional) Event loop to be used for asynchronous API method
     *                                                  calling mechanism
     *      'pumpTaskQueue' => [bool] - (optional, default: true) Indicates whether to force the promise task queue to
     *                                   be periodically run. Note that, if this option is set to false, the user
     *                                   should be responsible to periodically run the task queue by his/her own. This
     *                                   option is only processed when an event loop is provided
     *      'pumpInterval' => [int]   - (optional, default: 10) Time, in milliseconds, specifying the interval for
     *                                   periodically runing the task queue. This option is only processed when an
     *                                   event loop is provided and the 'pumpTaskQueue' option is set to true
     * @throws Exception
     */
    public function __construct($deviceId, $apiAccessSecret, array $options = null)
    {
        $hostName = 'catenis.io';
        $subdomain = '';
        $secure = true;
        $version = '0.11';
        $timeout = 0;
        $httpClientHandler = null;

        $this->useCompression = true;
        $this->compressThreshold = 1024;
    
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

            if (isset($options['useCompression'])) {
                $optUseCompr = $options['useCompression'];

                if (is_bool($optUseCompr)) {
                    $this->useCompression = $optUseCompr;
                }
            }
    
            if (isset($options['compressThreshold'])) {
                $optComprThrsh = $options['compressThreshold'];

                if (is_int($optComprThrsh) && $optComprThrsh > 0) {
                    $this->compressThreshold = $optComprThrsh;
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
                        $pumpInterval = 0.01;

                        if (isset($options['pumpInterval'])) {
                            $optPumpInterval = $options['pumpInterval'];
    
                            if (is_int($optPumpInterval) && $optPumpInterval > 0) {
                                $pumpInterval = $optPumpInterval / 1000;
                            }
                        }
    
                        $queue = Promise\queue();
                        $optEventLoop->addPeriodicTimer($pumpInterval, [$queue, 'run']);
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

        $wsUriScheme = $secure ? 'wss://' : 'ws://';
        $wsUriPrefix = $wsUriScheme . $host;
        $wsNtfyBaseUriPath = $apiBaseUriPath . self::$notifyRootPath . '/' . self::$wsNtfyRootPath . '/';
        $this->rootWsNtfyEndPoint = new Uri($wsUriPrefix . $wsNtfyBaseUriPath);

        // Instantiate HTTP client
        $this->httpClient = new Client([
            'handler' => $httpClientHandler,
            RequestOptions::HEADERS => [
                'User-Agent' => 'Catenis API PHP client',
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
     * @param string|array $message - The message to store. If a string is passed, it is assumed to be the whole
     *                                 message's contents. Otherwise, it is expected that the message be passed in
     *                                 chunks using the following map (associative array) to control it:
     *      'data' => [string],             (optional) The current message data chunk. The actual message's contents
     *                                       should be comprised of one or more data chunks. NOTE that, when sending a
     *                                       final message data chunk (isFinal = true and continuationToken specified),
     *                                       this parameter may either be omitted or have an empty string value
     *      'isFinal' => [bool],            (optional, default: "true") Indicates whether this is the final (or the
     *                                       single) message data chunk
     *      'continuationToken' => [string] (optional) - Indicates that this is a continuation message data chunk.
     *                                       This should be filled with the value returned in the 'continuationToken'
     *                                       field of the response from the previously sent message data chunk
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'encoding' => [string],    (optional, default: 'utf8') One of the following values identifying the encoding
     *                                  of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [bool],       (optional, default: true) Indicates whether message should be encrypted before
     *                                  storing. NOTE that, when message is passed in chunks, this option is only taken
     *                                  into consideration (and thus only needs to be passed) for the final message
     *                                  data chunk, and it shall be applied to the message's contents as a whole
     *      'offChain' => [bool],      (optional, default: true) Indicates whether message should be processed as a
     *                                  Catenis off-chain message. Catenis off-chain messages are stored on the
     *                                  external storage repository and only later its reference is settled to the
     *                                  blockchain along with references of other off-chain messages. NOTE that, when
     *                                  message is passed in chunks, this option is only taken into consideration (and
     *                                  thus only needs to be passed) for the final message data chunk, and it shall be
     *                                  applied to the message's contents as a whole
     *      'storage' => [string],     (optional, default: 'auto') One of the following values identifying where the
     *                                  message should be stored: 'auto'|'embedded'|'external'. NOTE that, when message
     *                                  is passed in chunks, this option is only taken into consideration (and thus only
     *                                  needs to be passed) for the final message data chunk, and it shall be applied to
     *                                  the message's contents as a whole. ALSO note that, when the offChain option is
     *                                  set to true, this option's value is disregarded and the processing is done as
     *                                  if the value "external" was passed
     *      'async' => [bool]          (optional, default: false) - Indicates whether processing (storage of message to
     *                                  the blockchain) should be done asynchronously. If set to true, a provisional
     *                                  message ID is returned, which should be used to retrieve the processing outcome
     *                                  by calling the MessageProgress API method. NOTE that, when message is passed in
     *                                  chunks, this option is only taken into consideration (and thus only needs to be
     *                                  passed) for the final message data chunk, and it shall be applied to the
     *                                  message's contents as a whole
     * @return stdClass - An object representing the JSON formatted data returned by the Log Message Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function logMessage($message, array $options = null)
    {
        return $this->sendPostRequest(...self::logMessageRequestParams($message, $options));
    }

    /**
     * Send a message
     * @param string|array $message - The message to send. If a string is passed, it is assumed to be the whole
     *                                 message's contents. Otherwise, it is expected that the message be passed in
     *                                 chunks using the following map (associative array) to control it:
     *      'data' => [string],             (optional) The current message data chunk. The actual message's contents
     *                                       should be comprised of one or more data chunks. NOTE that, when sending a
     *                                       final message data chunk (isFinal = true and continuationToken specified),
     *                                       this parameter may either be omitted or have an empty string value
     *      'isFinal' => [bool],            (optional, default: "true") Indicates whether this is the final (or the
     *                                       single) message data chunk
     *      'continuationToken' => [string] (optional) - Indicates that this is a continuation message data chunk.
     *                                       This should be filled with the value returned in the 'continuationToken'
     *                                       field of the response from the previously sent message data chunk
     * @param array $targetDevice - A map (associative array) containing the following keys:
     *      'id' => [string],          ID of target device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [bool] (optional, default: false) Indicates whether supply ID is a product unique
     *                                       ID (otherwise, it should be a Catenis device ID)
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'encoding' => [string],       (optional, default: 'utf8') One of the following values identifying the
     *                                     encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [bool],          (optional, default: true) Indicates whether message should be encrypted
     *                                     before storing. NOTE that, when message is passed in chunks, this option is
     *                                     only taken into consideration (and thus only needs to be passed) for the
     *                                     final message data chunk, and it shall be applied to the message's contents
     *                                     as a whole
     *      'offChain' => [bool],         (optional, default: true) Indicates whether message should be processed as a
     *                                     Catenis off-chain message. Catenis off-chain messages are stored on the
     *                                     external storage repository and only later its reference is settled to the
     *                                     blockchain along with references of other off-chain messages. NOTE that, when
     *                                     message is passed in chunks, this option is only taken into consideration
     *                                     (and thus only needs to be passed) for the final message data chunk, and it
     *                                     shall be applied to the message's contents as a whole
     *      'storage' => [string],        (optional, default: 'auto') One of the following values identifying where the
     *                                     message should be stored: 'auto'|'embedded'|'external'. NOTE that, when
     *                                     message is passed in chunks, this option is only taken into consideration
     *                                     (and thus only needs to be passed) for the final message data chunk, and it
     *                                     shall be applied to the message's contents as a whole. ALSO note that, when
     *                                     the offChain option is set to true, this option's value is disregarded and
     *                                     the processing is done as if the value "external" was passed
     *      'readConfirmation' => [bool], (optional, default: false) Indicates whether message should be sent with read
     *                                     confirmation enabled. NOTE that, when message is passed in chunks, this
     *                                     option is only taken into consideration (and thus only needs to be passed)
     *                                     for the final message data chunk, and it shall be applied to the message's
     *                                     contents as a whole
     *      'async' => [bool]             (optional, default: false) - Indicates whether processing (storage of message
     *                                     to the blockchain) should be done asynchronously. If set to true, a
     *                                     provisional message ID is returned, which should be used to retrieve the
     *                                     processing outcome by calling the MessageProgress API method. NOTE that,
     *                                     when message is passed in chunks, this option is only taken into
     *                                     consideration (and thus only needs to be passed) for the final message data
     *                                     chunk, and it shall be applied to the message's contents as a whole
     * @return stdClass - An object representing the JSON formatted data returned by the Log Message Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function sendMessage($message, array $targetDevice, array $options = null)
    {
        return $this->sendPostRequest(...self::sendMessageRequestParams($message, $targetDevice, $options));
    }

    /**
     * Read a message
     * @param string $messageId - The ID of the message to read
     * @param string|array|null options - (optional) If a string is passed, it is assumed to be the value for the
     *                                     (single) 'encoding' option. Otherwise, it should be a map (associative array)
     *                                     containing the following keys:
     *      'encoding' => [string],          (optional, default: 'utf8') One of the following values identifying the
     *                                        encoding that should be used for the returned message: 'utf8'|'base64'|
     *                                        'hex'
     *      'continuationToken' => [string], (optional) Indicates that this is a continuation call and that the
     *                                        following message data chunk should be returned. This should be filled
     *                                        with the value returned in the 'continuationToken' field of the response
     *                                        from the previous call, or the response from the Retrieve Message
     *                                        Progress API method
     *      'dataChunkSize' => [int],        (optional) Size, in bytes, of the largest message data chunk that should
     *                                        be returned. This is effectively used to signal that the message should
     *                                        be retrieved/read in chunks. NOTE that this option is only taken into
     *                                        consideration (and thus only needs to be passed) for the initial call to
     *                                        this API method with a given message ID (no continuation token), and it
     *                                        shall be applied to the message's contents as a whole
     *      'async' =>  [bool]               (optional, default: false) Indicates whether processing (retrieval of
     *                                        message from the blockchain) should be done asynchronously. If set to
     *                                        true, a cached message ID is returned, which should be used to retrieve
     *                                        the processing outcome by calling the Retrieve Message Progress API
     *                                        method. NOTE that this option is only taken into consideration (and thus
     *                                        only needs to be passed) for the initial call to this API method with a
     *                                        given message ID (no continuation token), and it shall be applied to the
     *                                        message's contents as a whole
     * @return stdClass - An object representing the JSON formatted data returned by the Read Message Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function readMessage($messageId, $options = null)
    {
        return $this->sendGetRequest(...self::readMessageRequestParams($messageId, $options));
    }

    /**
     * Retrieve message container
     * @param string $messageId - The ID of message to retrieve container info
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Message Container
     *                     Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrieveMessageContainer($messageId)
    {
        return $this->sendGetRequest(...self::retrieveMessageContainerRequestParams($messageId));
    }

    /**
     * Retrieve message origin
     * @param string $messageId - The ID of message to retrieve origin info
     * @param string|null $msgToSign A message (any text) to be signed using the Catenis message's origin device's
     *                                private key. The resulting signature can then later be independently verified to
     *                                prove the Catenis message origin
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Message Container
     *                     Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrieveMessageOrigin($messageId, $msgToSign = null)
    {
        return $this->sendGetRequest(...self::retrieveMessageOriginRequestParams($messageId, $msgToSign));
    }

    /**
     * Retrieve asynchronous message processing progress
     * @param string $messageId - ID of ephemeral message (either a provisional or a cached message) for which to
     *                             return processing progress
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Message Container
     *                     Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrieveMessageProgress($messageId)
    {
        return $this->sendGetRequest(...self::retrieveMessageProgressRequestParams($messageId));
    }

    /**
     * List messages
     * @param array|null $selector - (optional) A map (associative array) containing the following keys:
     *      'action' => [string]              (optional, default: 'any') One of the following values specifying the
     *                                         action originally performed on the messages intended to be retrieved:
     *                                         'log'|'send'|'any'
     *      'direction' => [string]           (optional, default: 'any') One of the following values specifying the
     *                                         direction of the sent messages intended to be retrieve: 'inbound'|
     *                                         'outbound'|'any'. Note that this option only applies to sent messages
     *                                         (action = 'send'). 'inbound' indicates messages sent to the device that
     *                                         issued the request, while 'outbound' indicates messages sent from the
     *                                         device that issued the request
     *      'fromDevices' => [array]          (optional) A list (simple array) of devices from which the messages
     *                                         intended to be retrieved had been sent. Note that this option only
     *                                         applies to messages sent to the device that issued the request (action =
     *                                         'send' and direction = 'inbound')
     *          [n] => [array]                  Each item of the list is a map (associative array) containing the
     *                                           following keys:
     *              'id' => [string]               ID of the device. Should be a Catenis device ID unless
     *                                              isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]  (optional, default: false) Indicates whether supplied ID is a
     *                                              product unique ID (otherwise, it should be a Catenis device ID)
     *      'toDevices' => [array]            (optional) A list (simple array) of devices to which the messages
     *                                         intended to be retrieved had been sent. Note that this option only
     *                                         applies to messages sent from the device that issued the request (action
     *                                         = 'send' and direction = 'outbound')
     *          [n] => [array]                  Each item of the list is a map (associative array) containing the
     *                                           following keys:
     *              'id' => [string]               ID of the device. Should be a Catenis device ID unless
     *                                              isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]  (optional, default: false) Indicates whether supplied ID is a
     *                                              product unique ID (otherwise, it should be a Catenis device ID)
     *      'readState' => [string]           (optional, default: 'any') One of the following values indicating the
     *                                          current read state of the the messages intended to be retrieved:
     *                                          'unread'|'read'|'any'.
     *      'startDate' => [string|DateTime]  (optional) Date and time specifying the lower boundary of the time frame
     *                                         within which the messages intended to be retrieved has been: logged, in
     *                                         case of messages logged by the device that issued the request (action =
     *                                         'log'); sent, in case of messages sent from the current device (action =
     *                                         'send' direction = 'outbound'); or received, in case of messages sent to
     *                                         the device that issued the request (action = 'send' and direction =
     *                                         'inbound'). Note: if a string is passed, assumes that it is an ISO 8601
     *                                         formatter date/time
     *      'endDate' => [string|DateTime]    (optional) Date and time specifying the upper boundary of the time frame
     *                                         within which the messages intended to be retrieved has been: logged, in
     *                                         case of messages logged by the device that issued the request (action =
     *                                         'log'); sent, in case of messages sent from the current device (action =
     *                                         'send' direction = 'outbound'); or received, in case of messages sent to
     *                                         the device that issued the request (action = 'send' and direction =
     *                                         'inbound'). Note: if a string is passed, assumes that it is an ISO 8601
     *                                         formatter date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of messages that should be returned
     * @param int|null $skip - (optional, default: 0) Number of messages that should be skipped (from beginning of list
     *                          of matching messages) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Messages Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listMessages(array $selector = null, $limit = null, $skip = null)
    {
        return $this->sendGetRequest(...self::listMessagesRequestParams($selector, $limit, $skip));
    }

    /**
     * List permission events
     * @return stdClass - An object representing the JSON formatted data returned by the List Permission Events Catenis
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listPermissionEvents()
    {
        return $this->sendGetRequest(...self::listPermissionEventsRequestParams());
    }

    /**
     * Retrieve permission rights
     * @param string $eventName - Name of permission event
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Permission Rights
     *                     Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrievePermissionRights($eventName)
    {
        return $this->sendGetRequest(...self::retrievePermissionRightsRequestParams($eventName));
    }

    /**
     * Set permission rights
     * @param string $eventName - Name of permission event
     * @param array $rights - A map (associative array) containing the following keys:
     *      'system' => [string]        (optional) Permission right to be attributed at system level for the specified
     *                                   event. Must be one of the following values: 'allow', 'deny'
     *      'catenisNode' => [array]    (optional) A map (associative array), specifying the permission rights to be
     *                                   attributed at the Catenis node level for the specified event, with the
     *                                   following keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of indices (or a single index) of Catenis
     *                                        nodes to be given allow right. Can optionally include the value 'self' to
     *                                        refer to the index of the Catenis node to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis
     *                                        nodes to be given deny right. Can optionally include the value 'self' to
     *                                        refer to the index of the Catenis node to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis
     *                                        nodes the rights of which should be removed. Can optionally include the
     *                                        value 'self' to refer to the index of the Catenis node to which the
     *                                        device belongs. The wildcard character ('*') can also be used to indicate
     *                                        that the rights for all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be
     *                                   attributed at the client level for the specified event, with the following
     *                                   keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of IDs (or a single ID) of clients to be
     *                                        given allow right. Can optionally include the value 'self' to refer to
     *                                        the ID of the client to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients to be
     *                                        given deny right. Can optionally include the value 'self' to refer to the
     *                                        ID of the client to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients the
     *                                        rights of which should be removed. Can optionally include the value
     *                                        'self' to refer to the ID of the client to which the device belongs. The
     *                                        wildcard character ('*') can also be used to indicate that the rights for
     *                                        all clients should be remove
     *      'device' => [array]         (optional) A map (associative array), specifying the permission rights to be
     *                                   attributed at the device level for the specified event, with the following
     *                                   keys:
     *          'allow' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given
     *                                 allow right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative
     *                                         array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless
     *                                                   isProdUniqueId is true. Can optionally be replaced with value
     *                                                   'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a
     *                                                   product unique ID (otherwise, it should be a Catenis device ID)
     *          'deny' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given
     *                                deny right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative
     *                                         array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless
     *                                                   isProdUniqueId is true. Can optionally be replaced with value
     *                                                   'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a
     *                                                   product unique ID (otherwise, it should be a Catenis device ID)
     *          'none' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices the rights of
     *                                which should be removed.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative
     *                                         array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless
     *                                                   isProdUniqueId is true. Can optionally be replaced with value
     *                                                   'self' to refer to the ID of the device itself. The wildcard
     *                                                   character ('*') can also be used to indicate that the rights
     *                                                   for all devices should be remove
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a
     *                                                   product unique ID (otherwise, it should be a Catenis device
     *                                                   ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Set Permission Rights Catenis
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function setPermissionRights($eventName, $rights)
    {
        return $this->sendPostRequest(...self::setPermissionRightsRequestParams($eventName, $rights));
    }

    /**
     * Check effective permission right
     * @param string $eventName - Name of permission event
     * @param string $deviceId - ID of the device to check the permission right applied to it. Can optionally be
     *                            replaced with value 'self' to refer to the ID of the device that issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be
     *                                interpreted as a product unique ID (otherwise, it is interpreted as a Catenis
     *                                device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Check Effective Permission
     *                     Right Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function checkEffectivePermissionRight($eventName, $deviceId, $isProdUniqueId = false)
    {
        return $this->sendGetRequest(...self::checkEffectivePermissionRightRequestParams(
            $eventName,
            $deviceId,
            $isProdUniqueId
        ));
    }

    /**
     * List notification events
     * @return stdClass - An object representing the JSON formatted data returned by the List Notification Events
     *                     Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listNotificationEvents()
    {
        return $this->sendGetRequest(...self::listNotificationEventsRequestParams());
    }

    /**
     * Retrieve device identification information
     * @param string $deviceId - ID of the device the identification information of which is to be retrieved. Can
     *                            optionally be replaced with value 'self' to refer to the ID of the device that issued
     *                            the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be
     *                                interpreted as a product unique ID (otherwise, it is interpreted as a Catenis
     *                                device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Device Identification
     *                     Info Catenis API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrieveDeviceIdentificationInfo($deviceId, $isProdUniqueId = false)
    {
        return $this->sendGetRequest(...self::retrieveDeviceIdentificationInfoRequestParams(
            $deviceId,
            $isProdUniqueId
        ));
    }

    /**
     * Issue an amount of a new asset
     * @param array $assetInfo - A map (associative array), specifying the information for creating the new asset, with
     *                            the following keys:
     *      'name' => [string]         The name of the asset
     *      'description' => [string]  (optional) The description of the asset
     *      'canReissue' => [bool]     Indicates whether more units of this asset can be issued at another time (an
     *                                  unlocked asset)
     *      'decimalPlaces' => [int]   The number of decimal places that can be used to specify a fractional amount of
     *                                  this asset
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative
     *                                     array), specifying the device for which the asset is issued and that shall
     *                                     hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of holding device. Should be a Catenis device ID unless isProdUniqueId
     *                                         is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product
     *                                         unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Issue Asset Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function issueAsset(array $assetInfo, $amount, array $holdingDevice = null)
    {
        return $this->sendPostRequest(...self::issueAssetRequestParams($assetInfo, $amount, $holdingDevice));
    }

    /**
     * Issue an additional amount of an existing asset
     * @param string $assetId - ID of asset to issue more units of it
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative
     *                                     array), specifying the device for which the asset is issued and that shall
     *                                     hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of holding device. Should be a Catenis device ID unless isProdUniqueId
     *                                         is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product
     *                                         unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Reissue Asset Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function reissueAsset($assetId, $amount, array $holdingDevice = null)
    {
        return $this->sendPostRequest(...self::reissueAssetRequestParams($assetId, $amount, $holdingDevice));
    }

    /**
     * Transfer an amount of an asset to a device
     * @param string $assetId - ID of asset to transfer
     * @param float $amount - Amount of asset to be transferred (expressed as a decimal amount)
     * @param array $receivingDevice - (optional, default: device that issues the request) A map (associative array),
     *                                  specifying the device Device to which the asset is to be transferred and that
     *                                  shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of receiving device. Should be a Catenis device ID unless
     *                                         isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product
     *                                         unique ID (otherwise, it should be a Catenis device ID)
     * @return stdClass - An object representing the JSON formatted data returned by the Transfer Asset Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function transferAsset($assetId, $amount, array $receivingDevice)
    {
        return $this->sendPostRequest(...self::transferAssetRequestParams($assetId, $amount, $receivingDevice));
    }

    /**
     * Retrieve information about a given asset
     * @param string $assetId - ID of asset to retrieve information
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Asset Info Catenis
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrieveAssetInfo($assetId)
    {
        return $this->sendGetRequest(...self::retrieveAssetInfoRequestParams($assetId));
    }

    /**
     * Get the current balance of a given asset held by the device
     * @param string $assetId - ID of asset to get balance
     * @return stdClass - An object representing the JSON formatted data returned by the Get Asset Balance Catenis API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function getAssetBalance($assetId)
    {
        return $this->sendGetRequest(...self::getAssetBalanceRequestParams($assetId));
    }

    /**
     * List assets owned by the device
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of
     *                          list) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Owned Assets API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listOwnedAssets($limit = null, $skip = null)
    {
        return $this->sendGetRequest(...self::listOwnedAssetsRequestParams($limit, $skip));
    }

    /**
     * List assets issued by the device
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of
     *                          list) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Issued Assets API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listIssuedAssets($limit = null, $skip = null)
    {
        return $this->sendGetRequest(...self::listIssuedAssetsRequestParams($limit, $skip));
    }

    /**
     * Retrieve issuance history for a given asset
     * @param string $assetId - ID of asset to retrieve issuance history
     * @param string|DateTime|null $startDate - (optional) Date and time specifying the lower boundary of the time
     *                                           frame within which the issuance events intended to be retrieved have
     *                                           occurred. The returned issuance events must have occurred not before
     *                                           that date/time. Note: if a string is passed, it should be an ISO 8601
     *                                           formatted date/time
     * @param string|DateTime|null $endDate - (optional) Date and time specifying the upper boundary of the time frame
     *                                         within which the issuance events intended to be retrieved have occurred.
     *                                         The returned issuance events must have occurred not after that date/
     *                                         time. Note: if a string is passed, it should be an ISO 8601 formatted
     *                                         date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of asset issuance events that should be returned
     * @param int|null $skip - (optional, default: 0) Number of asset issuance events that should be skipped (from
     *                          beginning of list of matching events) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the Retrieve Asset Issuance
     *                     History API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function retrieveAssetIssuanceHistory(
        $assetId,
        $startDate = null,
        $endDate = null,
        $limit = null,
        $skip = null
    ) {
        return $this->sendGetRequest(...self::retrieveAssetIssuanceHistoryRequestParams(
            $assetId,
            $startDate,
            $endDate,
            $limit,
            $skip
        ));
    }

    /**
     * List devices that currently hold any amount of a given asset
     * @param string $assetId - ID of asset to get holders
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of
     *                          list) and not returned
     * @return stdClass - An object representing the JSON formatted data returned by the List Asset Holders API
     *                     endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listAssetHolders($assetId, $limit = null, $skip = null)
    {
        return $this->sendGetRequest(...self::listAssetHoldersRequestParams($assetId, $limit, $skip));
    }

    /**
     * Export an asset to a foreign blockchain, by creating a new (ERC-20 compliant) token on that blockchain
     *
     * @param string $assetId - The ID of asset to export
     * @param string $foreignBlockchain - The key identifying the foreign blockchain. Valid options: 'ethereum',
     *                                     'binance', 'polygon'
     * @param array $token - A map (associative array) containing the following keys:
     *      'id' => [string]            The name of the token to be created on the foreign blockchain
     *      'symbol' => [string]        The symbol of the token to be created on the foreign blockchain
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'consumptionProfile' => [string]    (optional) Name of the foreign blockchain's native coin consumption
     *                                           profile to use. Valid options: 'fastest', 'fast', 'average', 'slow'
     *      'estimateOnly' => [bool]            (optional, default: false) When set, indicates that no asset export
     *                                           should be executed but only the estimated price (in the foreign
     *                                           blockchain's native coin) to fulfill the operation should be returned
     * @return stdClass - An object representing the JSON formatted data returned by the Export Asset API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function exportAsset($assetId, $foreignBlockchain, array $token, $options = null)
    {
        return $this->sendPostRequest(...self::exportAssetRequestParams(
            $assetId,
            $foreignBlockchain,
            $token,
            $options
        ));
    }

    /**
     * Migrate an amount of a previously exported asset to/from the foreign blockchain token
     *
     * @param string $assetId - The ID of the asset to migrate an amount of it
     * @param string $foreignBlockchain - The key identifying the foreign blockchain. Valid options: 'ethereum',
     *                                     'binance', 'polygon'
     * @param array|string $migration - A map (associative array) describing a new asset migration, with the keys listed
     *                                   below. Otherwise, if a string value is passed, it is assumed to be the ID of
     *                                   the asset migration to be reprocessed.
     *      'direction' => [string]         The direction of the migration. Valid options: 'outward', 'inward'
     *      'amount' => [float]             The amount (as a decimal value) of the asset to be migrated
     *      'destAddress' => [string]       (optional) The address of the account on the foreign blockchain that should
     *                                       be credited with the specified amount of the foreign token
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'consumptionProfile' => [string]    (optional) Name of the foreign blockchain's native coin consumption
     *                                           profile to use. Valid options: 'fastest', 'fast', 'average', 'slow'
     *      'estimateOnly' => [bool]            (optional, default: false) When set, indicates that no asset migration
     *                                           should be executed but only the estimated price (in the foreign
     *                                           blockchain's native coin) to fulfill the operation should be returned
     * @return stdClass - An object representing the JSON formatted data returned by the Migrate Asset API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function migrateAsset($assetId, $foreignBlockchain, $migration, $options = null)
    {
        return $this->sendPostRequest(...self::migrateAssetRequestParams(
            $assetId,
            $foreignBlockchain,
            $migration,
            $options
        ));
    }

    /**
     * Retrieve the current information about the outcome of an asset export
     *
     * @param string $assetId - The ID of the asset that was exported
     * @param string $foreignBlockchain - The key identifying the foreign blockchain. Valid options: 'ethereum',
     *                                     'binance', 'polygon'
     * @return stdClass - An object representing the JSON formatted data returned by the Asset Export Outcome
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function assetExportOutcome($assetId, $foreignBlockchain)
    {
        return $this->sendGetRequest(...self::assetExportOutcomeRequestParams($assetId, $foreignBlockchain));
    }

    /**
     * Retrieve the current information about the outcome of an asset migration
     *
     * @param string $migrationId - The ID of the asset migration
     * @return stdClass - An object representing the JSON formatted data returned by the Asset Migration Outcome
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function assetMigrationOutcome($migrationId)
    {
        return $this->sendGetRequest(...self::assetMigrationOutcomeRequestParams($migrationId));
    }

    /**
     * Retrieves a list of issued asset exports that satisfy a given search criteria
     *
     * @param array|null $selector - (optional) A map (associative array) containing the following keys:
     *      'assetId' => [string]               (optional) The ID of the exported asset
     *      'foreignBlockchain' => [string]     (optional) The key identifying the foreign blockchain to where the asset
     *                                           has been exported. Valid options: 'ethereum', 'binance', 'polygon'
     *      'tokenSymbol' => [string]           (optional) The symbol of the resulting foreign token
     *      'status' => [string]                (optional) A single status or a comma-separated list of statuses to
     *                                           include. Valid options: 'pending', 'success, 'error'
     *      'negateStatus' => [bool]            (optional, default: false) Boolean value indicating whether the
     *                                           specified statuses should be excluded instead
     *      'startDate' => [string|DateTime]    (optional) Date and time specifying the inclusive lower bound of the
     *                                           time frame within which the asset has been exported. If a string is
     *                                           passed, it should be an ISO 8601 formatted date/time
     *      'endDate' => [string:DateTime]      (optional) Date and time specifying the inclusive upper bound of the
     *                                           time frame within which the asset has been exported. If a string is
     *                                           passed, it should be an ISO 8601 formatted date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of asset exports that should be returned. Must
     *                           be a positive integer value not greater than 500
     * @param int|null $skip - (optional, default: 0) Number of asset exports that should be skipped (from beginning of
     *                          list of matching asset exports) and not returned. Must be a non-negative (includes
     *                          zero) integer value
     * @return stdClass - An object representing the JSON formatted data returned by the List Exported Assets
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listExportedAssets($selector = null, $limit = null, $skip = null)
    {
        return $this->sendGetRequest(...self::listExportedAssetsRequestParams($selector, $limit, $skip));
    }

    /**
     * Retrieves a list of issued asset migrations that satisfy a given search criteria
     *
     * @param array|null $selector - (optional) A map (associative array) containing the following keys:
     *      'assetId' => [string]               (optional) The ID of the asset the amount of which has been migrated
     *      'foreignBlockchain' => [string]     (optional) The key identifying the foreign blockchain to/from where the
     *                                           asset amount has been migrated. Valid options: 'ethereum', 'binance',
     *                                           'polygon'
     *      'direction' => [string]             (optional) The direction of the migration. Valid options: 'outward',
     *                                           'inward'
     *      'status' => [string]                (optional) A single status or a comma-separated list of statuses to
     *                                           include. Valid options: 'pending', 'interrupted', 'success', 'error'
     *      'negateStatus' => [bool]            (optional, default: false) Boolean value indicating whether the
     *                                           specified statuses should be excluded instead
     *      'startDate' => [string|DateTime]    (optional) Date and time specifying the inclusive lower bound of the
     *                                           time frame within which the asset amount has been migrated. If a
     *                                           string is passed, it should be an ISO 8601 formatted date/time
     *      'endDate' => [string|DateTime]      (optional) Date and time specifying the inclusive upper bound of the
     *                                           time frame within which the asset amount has been migrated. If a
     *                                           string is passed, it should be an ISO 8601 formatted date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of asset migrations that should be returned.
     *                           Must be a positive integer value not greater than 500
     * @param int|null $skip - (optional, default: 0) Number of asset migrations that should be skipped (from beginning
     *                          of list of matching asset migrations) and not returned. Must be a non-negative
     *                          (includes zero) integer value
     * @return stdClass - An object representing the JSON formatted data returned by the List Asset Migrations
     *                     API endpoint
     * @throws CatenisClientException
     * @throws CatenisApiException
     */
    public function listAssetMigrations($selector = null, $limit = null, $skip = null)
    {
        return $this->sendGetRequest(...self::listAssetMigrationsRequestParams($selector, $limit, $skip));
    }

    /**
     * Create WebSocket Notification Channel for a given notification event
     * @param string $eventName - Name of Catenis notification event
     * @return WsNotifyChannel - Catenis notification channel object
     */
    public function createWsNotifyChannel($eventName)
    {
        return new WsNotifyChannel($this, $eventName);
    }

    // Asynchronous processing methods
    //
    /**
     * Log a message asynchronously
     * @param string|array $message - The message to store. If a string is passed, it is assumed to be the whole
     *                                 message's contents. Otherwise, it is expected that the message be passed in
     *                                 chunks using the following map (associative array) to control it:
     *      'data' => [string],             (optional) The current message data chunk. The actual message's contents
     *                                       should be comprised of one or more data chunks. NOTE that, when sending a
     *                                       final message data chunk (isFinal = true and continuationToken specified),
     *                                       this parameter may either be omitted or have an empty string value
     *      'isFinal' => [bool],            (optional, default: "true") Indicates whether this is the final (or the
     *                                       single) message data chunk
     *      'continuationToken' => [string] (optional) - Indicates that this is a continuation message data chunk.
     *                                       This should be filled with the value returned in the 'continuationToken'
     *                                       field of the response from the previously sent message data chunk
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'encoding' => [string],    (optional, default: 'utf8') One of the following values identifying the encoding
     *                                  of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [bool],       (optional, default: true) Indicates whether message should be encrypted before
     *                                  storing. NOTE that, when message is passed in chunks, this option is only taken
     *                                  into consideration (and thus only needs to be passed) for the final message
     *                                  data chunk, and it shall be applied to the message's contents as a whole
     *      'offChain' => [bool],      (optional, default: true) Indicates whether message should be processed as a
     *                                  Catenis off-chain message. Catenis off-chain messages are stored on the
     *                                  external storage repository and only later its reference is settled to the
     *                                  blockchain along with references of other off-chain messages. NOTE that, when
     *                                  message is passed in chunks, this option is only taken into consideration (and
     *                                  thus only needs to be passed) for the final message data chunk, and it shall be
     *                                  applied to the message's contents as a whole
     *      'storage' => [string],     (optional, default: 'auto') One of the following values identifying where the
     *                                  message should be stored: 'auto'|'embedded'|'external'. NOTE that, when message
     *                                  is passed in chunks, this option is only taken into consideration (and thus only
     *                                  needs to be passed) for the final message data chunk, and it shall be applied to
     *                                  the message's contents as a whole. ALSO note that, when the offChain option is
     *                                  set to true, this option's value is disregarded and the processing is done as
     *                                  if the value "external" was passed
     *      'async' => [bool]          (optional, default: false) - Indicates whether processing (storage of message to
     *                                  the blockchain) should be done asynchronously. If set to true, a provisional
     *                                  message ID is returned, which should be used to retrieve the processing outcome
     *                                  by calling the MessageProgress API method. NOTE that, when message is passed in
     *                                  chunks, this option is only taken into consideration (and thus only needs to be
     *                                  passed) for the final message data chunk, and it shall be applied to the
     *                                  message's contents as a whole
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function logMessageAsync($message, array $options = null)
    {
        return $this->sendPostRequestAsync(...self::logMessageRequestParams($message, $options));
    }

    /**
     * Send a message asynchronously
     * @param string|array $message - The message to send. If a string is passed, it is assumed to be the whole
     *                                 message's contents. Otherwise, it is expected that the message be passed in
     *                                 chunks using the following map (associative array) to control it:
     *      'data' => [string],             (optional) The current message data chunk. The actual message's contents
     *                                       should be comprised of one or more data chunks. NOTE that, when sending a
     *                                       final message data chunk (isFinal = true and continuationToken specified),
     *                                       this parameter may either be omitted or have an empty string value
     *      'isFinal' => [bool],            (optional, default: "true") Indicates whether this is the final (or the
     *                                       single) message data chunk
     *      'continuationToken' => [string] (optional) - Indicates that this is a continuation message data chunk.
     *                                       This should be filled with the value returned in the 'continuationToken'
     *                                       field of the response from the previously sent message data chunk
     * @param array $targetDevice - A map (associative array) containing the following keys:
     *      'id' => [string],          ID of target device. Should be a Catenis device ID unless isProdUniqueId is true
     *      'isProdUniqueId' => [bool] (optional, default: false) Indicates whether supply ID is a product unique
     *                                       ID (otherwise, it should be a Catenis device ID)
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'encoding' => [string],       (optional, default: 'utf8') One of the following values identifying the
     *                                     encoding of the message: 'utf8'|'base64'|'hex'
     *      'encrypt' => [bool],          (optional, default: true) Indicates whether message should be encrypted
     *                                     before storing. NOTE that, when message is passed in chunks, this option is
     *                                     only taken into consideration (and thus only needs to be passed) for the
     *                                     final message data chunk, and it shall be applied to the message's contents
     *                                     as a whole
     *      'offChain' => [bool],         (optional, default: true) Indicates whether message should be processed as a
     *                                     Catenis off-chain message. Catenis off-chain messages are stored on the
     *                                     external storage repository and only later its reference is settled to the
     *                                     blockchain along with references of other off-chain messages. NOTE that, when
     *                                     message is passed in chunks, this option is only taken into consideration
     *                                     (and thus only needs to be passed) for the final message data chunk, and it
     *                                     shall be applied to the message's contents as a whole
     *      'storage' => [string],        (optional, default: 'auto') One of the following values identifying where the
     *                                     message should be stored: 'auto'|'embedded'|'external'. NOTE that, when
     *                                     message is passed in chunks, this option is only taken into consideration
     *                                     (and thus only needs to be passed) for the final message data chunk, and it
     *                                     shall be applied to the message's contents as a whole. ALSO note that, when
     *                                     the offChain option is set to true, this option's value is disregarded and
     *                                     the processing is done as if the value "external" was passed
     *      'readConfirmation' => [bool], (optional, default: false) Indicates whether message should be sent with read
     *                                     confirmation enabled. NOTE that, when message is passed in chunks, this
     *                                     option is only taken into consideration (and thus only needs to be passed)
     *                                     for the final message data chunk, and it shall be applied to the message's
     *                                     contents as a whole
     *      'async' => [bool]             (optional, default: false) - Indicates whether processing (storage of message
     *                                     to the blockchain) should be done asynchronously. If set to true, a
     *                                     provisional message ID is returned, which should be used to retrieve the
     *                                     processing outcome by calling the MessageProgress API method. NOTE that,
     *                                     when message is passed in chunks, this option is only taken into
     *                                     consideration (and thus only needs to be passed) for the final message data
     *                                     chunk, and it shall be applied to the message's contents as a whole
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function sendMessageAsync($message, array $targetDevice, array $options = null)
    {
        return $this->sendPostRequestAsync(...self::sendMessageRequestParams($message, $targetDevice, $options));
    }

    /**
     * Read a message asynchronously
     * @param string $messageId - The ID of the message to read
     * @param string|array|null options - (optional) If a string is passed, it is assumed to be the value for the
     *                                     (single) 'encoding' option. Otherwise, it should be a map (associative array)
     *                                     containing the following keys:
     *      'encoding' => [string],          (optional, default: 'utf8') One of the following values identifying the
     *                                        encoding that should be used for the returned message: 'utf8'|'base64'|
     *                                        'hex'
     *      'continuationToken' => [string], (optional) Indicates that this is a continuation call and that the
     *                                        following message data chunk should be returned. This should be filled
     *                                        with the value returned in the 'continuationToken' field of the response
     *                                        from the previous call, or the response from the Retrieve Message
     *                                        Progress API method
     *      'dataChunkSize' => [int],        (optional) Size, in bytes, of the largest message data chunk that should
     *                                        be returned. This is effectively used to signal that the message should
     *                                        be retrieved/read in chunks. NOTE that this option is only taken into
     *                                        consideration (and thus only needs to be passed) for the initial call to
     *                                        this API method with a given message ID (no continuation token), and it
     *                                        shall be applied to the message's contents as a whole
     *      'async' =>  [bool]               (optional, default: false) Indicates whether processing (retrieval of
     *                                        message from the blockchain) should be done asynchronously. If set to
     *                                        true, a cached message ID is returned, which should be used to retrieve
     *                                        the processing outcome by calling the Retrieve Message Progress API
     *                                        method. NOTE that this option is only taken into consideration (and thus
     *                                        only needs to be passed) for the initial call to this API method with a
     *                                        given message ID (no continuation token), and it shall be applied to the
     *                                        message's contents as a whole
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function readMessageAsync($messageId, $options = null)
    {
        return $this->sendGetRequestAsync(...self::readMessageRequestParams($messageId, $options));
    }

    /**
     * Retrieve message container asynchronously
     * @param string $messageId - The ID of message to retrieve container info
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function retrieveMessageContainerAsync($messageId)
    {
        return $this->sendGetRequestAsync(...self::retrieveMessageContainerRequestParams($messageId));
    }

    /**
     * Retrieve message origin asynchronously
     * @param string $messageId The ID of message to retrieve origin info
     * @param string|null $msgToSign A message (any text) to be signed using the Catenis message's origin device's
     *                                private key. The resulting signature can then later be independently verified to
     *                                prove the Catenis message origin
     * @return PromiseInterface A promise representing the asynchronous processing
     */
    public function retrieveMessageOriginAsync($messageId, $msgToSign = null)
    {
        return $this->sendGetRequestAsync(...self::retrieveMessageOriginRequestParams($messageId, $msgToSign));
    }

    /**
     * Retrieve asynchronous message processing progress asynchronously
     * @param string $messageId - ID of ephemeral message (either a provisional or a cached message) for which to
     *                             return processing progress
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function retrieveMessageProgressAsync($messageId)
    {
        return $this->sendGetRequestAsync(...self::retrieveMessageProgressRequestParams($messageId));
    }

    /**
     * List messages asynchronously
     * @param array|null $selector - A map (associative array) containing the following keys:
     *      'action' => [string]                  (optional, default: 'any') - One of the following values specifying
     *                                             the action originally performed on the messages intended to be
     *                                             retrieved: 'log'|'send'|'any'
     *      'direction' => [string]               (optional, default: 'any') - One of the following values specifying
     *                                             the direction of the sent messages intended to be retrieve:
     *                                             'inbound'|'outbound'|'any'. Note that this option only applies to
     *                                             sent messages (action = 'send'). 'inbound' indicates messages sent
     *                                             to the device that issued the request, while 'outbound' indicates
     *                                             messages sent from the device that issued the request
     *      'fromDevices' => [array]              (optional) - A list (simple array) of devices from which the messages
     *                                             intended to be retrieved had been sent. Note that this option only
     *                                             applies to messages sent to the device that issued the request
     *                                             (action = 'send' and direction = 'inbound')
     *          [n] => [array]                      Each item of the list is a map (associative array) containing the
     *                                               following keys:
     *              'id' => [string]                   ID of the device. Should be a Catenis device ID unless
     *                                                  isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]      (optional, default: false) Indicates whether supplied ID is a
     *                                                  product unique ID (otherwise, it should be a Catenis device ID)
     *      'toDevices' => [array]                (optional) - A list (simple array) of devices to which the messages
     *                                             intended to be retrieved had been sent. Note that this option only
     *                                             applies to messages sent from the device that issued the request
     *                                             (action = 'send' and direction = 'outbound')
     *          [n] => [array]                      Each item of the list is a map (associative array) containing the
     *                                               following keys:
     *              'id' => [string]                   ID of the device. Should be a Catenis device ID unless
     *                                                  isProdUniqueId is true
     *              'isProdUniqueId' => [boolean]      (optional, default: false) Indicates whether supplied ID is a
     *                                                  product unique ID (otherwise, it should be a Catenis device ID)
     *      'readState' => [string]               (optional, default: 'any') - One of the following values indicating
     *                                             the current read state of the messages intended to be retrieved:
     *                                             'unread'|'read'|'any'.
     *      'startDate' => [string|DateTime]      (optional) - Date and time specifying the lower boundary of the time
     *                                             frame within which the messages intended to be retrieved has been:
     *                                             logged, in case of messages logged by the device that issued the
     *                                             request (action = 'log'); sent, in case of messages sent from the
     *                                             current device (action = 'send' direction = 'outbound'); or
     *                                             received, in case of messages sent to the device that issued the
     *                                             request (action = 'send' and direction = 'inbound'). Note: if a
     *                                             string is passed, it should be an ISO 8601 formatter date/time
     *      'endDate' => [string|DateTime]        (optional) - Date and time specifying the upper boundary of the time
     *                                             frame within which the messages intended to be retrieved has been:
     *                                             logged, in case of messages logged by the device that issued the
     *                                             request (action = 'log'); sent, in case of messages sent from the
     *                                             current device (action = 'send' direction = 'outbound'); or
     *                                             received, in case of messages sent to the device that issued the
     *                                             request (action = 'send' and direction = 'inbound'). Note: if a
     *                                             string is passed, it should be an ISO 8601 formatter date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of messages that should be returned
     * @param int|null $skip - (optional, default: 0) Number of messages that should be skipped (from beginning of list
     *                          of matching messages) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listMessagesAsync(array $selector = null, $limit = null, $skip = null)
    {
        return $this->sendGetRequestAsync(...self::listMessagesRequestParams($selector, $limit, $skip));
    }

    /**
     * List permission events asynchronously
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listPermissionEventsAsync()
    {
        return $this->sendGetRequestAsync(...self::listPermissionEventsRequestParams());
    }

    /**
     * Retrieve permission rights asynchronously
     * @param string $eventName - Name of permission event
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function retrievePermissionRightsAsync($eventName)
    {
        return $this->sendGetRequestAsync(...self::retrievePermissionRightsRequestParams($eventName));
    }

    /**
     * Set permission rights asynchronously
     * @param string $eventName - Name of permission event
     * @param array $rights - A map (associative array) containing the following keys:
     *      'system' => [string]        (optional) Permission right to be attributed at system level for the specified
     *                                   event. Must be one of the following values: 'allow', 'deny'
     *      'catenisNode' => [array]    (optional) A map (associative array), specifying the permission rights to be
     *                                   attributed at the Catenis node level for the specified event, with the
     *                                   following keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of indices (or a single index) of Catenis
     *                                        nodes to be given allow right. Can optionally include the value 'self' to
     *                                        refer to the index of the Catenis node to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis
     *                                        nodes to be given deny right. Can optionally include the value 'self' to
     *                                        refer to the index of the Catenis node to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of indices (or a single index) of Catenis
     *                                        nodes the rights of which should be removed. Can optionally include the
     *                                        value 'self' to refer to the index of the Catenis node to which the
     *                                        device belongs. The wildcard character ('*') can also be used to indicate
     *                                        that the rights for all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be
     *                                   attributed at the client level for the specified event, with the following
     *                                   keys:
     *          'allow' => [array|string]    (optional) A list (simple array) of IDs (or a single ID) of clients to be
     *                                        given allow right. Can optionally include the value 'self' to refer to
     *                                        the ID of the client to which the device belongs
     *          'deny' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients to be
     *                                        given deny right. Can optionally include the value 'self' to refer to the
     *                                        ID of the client to which the device belongs
     *          'none' => [array|string]     (optional) A list (simple array) of IDs (or a single ID) of clients the
     *                                        rights of which should be removed. Can optionally include the value
     *                                        'self' to refer to the ID of the client to which the device belongs. The
     *                                        wildcard character ('*') can also be used to indicate that the rights for
     *                                        all clients should be remove
     *      'client' => [array]         (optional) A map (associative array), specifying the permission rights to be
     *                                   attributed at the device level for the specified event, with the following
     *                                   keys:
     *          'allow' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given
     *                                 allow right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative
     *                                         array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless
     *                                                   isProdUniqueId is true. Can optionally be replaced with value
     *                                                   'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a
     *                                                   product unique ID (otherwise, it should be a Catenis device
     *                                                   ID)
     *          'deny' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices to be given
     *                                deny right.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative
     *                                         array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless
     *                                                   isProdUniqueId is true. Can optionally be replaced with value
     *                                                   'self' to refer to the ID of the device itself
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a
     *                                                   product unique ID (otherwise, it should be a Catenis device
     *                                                   ID)
     *          'none' => [array]    (optional) A list (simple array) of IDs (or a single ID) of devices the rights of
*                                     which should be removed.
     *              [n] => [array]            Each item of the list (or the single value given) is a map (associative
     *                                         array) containing the following keys:
     *                  'id' => [string]                ID of the device. Should be a Catenis device ID unless
     *                                                   isProdUniqueId is true. Can optionally be replaced with value
     *                                                   'self' to refer to the ID of the device itself. The wildcard
     *                                                   character ('*') can also be used to indicate that the rights
     *                                                   for all devices should be remove
     *                  'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a
     *                                                   product unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function setPermissionRightsAsync($eventName, $rights)
    {
        return $this->sendPostRequestAsync(...self::setPermissionRightsRequestParams($eventName, $rights));
    }

    /**
     * Check effective permission right asynchronously
     * @param string $eventName - Name of permission event
     * @param string $deviceId - ID of the device to check the permission right applied to it. Can optionally be
     *                            replaced with value 'self' to refer to the ID of the device that issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be
     *                                interpreted as a product unique ID (otherwise, it is interpreted as a Catenis
     *                                device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function checkEffectivePermissionRightAsync($eventName, $deviceId, $isProdUniqueId = false)
    {
        return $this->sendGetRequestAsync(...self::checkEffectivePermissionRightRequestParams(
            $eventName,
            $deviceId,
            $isProdUniqueId
        ));
    }

    /**
     * List notification events asynchronously
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listNotificationEventsAsync()
    {
        return $this->sendGetRequestAsync(...self::listNotificationEventsRequestParams());
    }

    /**
     * Retrieve device identification information asynchronously
     * @param string $deviceId - ID of the device the identification information of which is to be retrieved. Can
     *                            optionally be replaced with value 'self' to refer to the ID of the device that
     *                            issued the request
     * @param bool $isProdUniqueId - (optional, default: false) Indicates whether the deviceId parameter should be
     *                                interpreted as a product unique ID (otherwise, it is interpreted as a Catenis
     *                                device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function retrieveDeviceIdentificationInfoAsync($deviceId, $isProdUniqueId = false)
    {
        return $this->sendGetRequestAsync(...self::retrieveDeviceIdentificationInfoRequestParams(
            $deviceId,
            $isProdUniqueId
        ));
    }

    /**
     * Issue an amount of a new asset asynchronously
     * @param array $assetInfo - A map (associative array), specifying the information for creating the new asset, with
     *                            the following keys:
     *      'name' => [string]         The name of the asset
     *      'description' => [string]  (optional) The description of the asset
     *      'canReissue' => [bool]     Indicates whether more units of this asset can be issued at another time (an
     *                                  unlocked asset)
     *      'decimalPlaces' => [int]   The number of decimal places that can be used to specify a fractional amount of
     *                                  this asset
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative array),
     *                                     specifying the device for which the asset is issued and that shall hold the
     *                                     total issued amount, with the following keys:
     *      'id' => [string]                ID of holding device. Should be a Catenis device ID unless isProdUniqueId
     *                                       is true
     *      'isProdUniqueId' => [boolean]   (optional, default: false) Indicates whether supplied ID is a product
     *                                       unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function issueAssetAsync(array $assetInfo, $amount, array $holdingDevice = null)
    {
        return $this->sendPostRequestAsync(...self::issueAssetRequestParams($assetInfo, $amount, $holdingDevice));
    }

    /**
     * Issue an additional amount of an existing asset asynchronously
     * @param string $assetId - ID of asset to issue more units of it
     * @param float $amount - Amount of asset to be issued (expressed as a decimal amount)
     * @param array|null $holdingDevice - (optional, default: device that issues the request) A map (associative array),
     *                                     specifying the device for which the asset is issued and that shall hold the
     *                                     total issued amount, with the following keys:
     *      'id' => [string]                  ID of holding device. Should be a Catenis device ID unless isProdUniqueId
     *                                         is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product
     *                                         unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function reissueAssetAsync($assetId, $amount, array $holdingDevice = null)
    {
        return $this->sendPostRequestAsync(...self::reissueAssetRequestParams($assetId, $amount, $holdingDevice));
    }

    /**
     * Transfer an amount of an asset to a device asynchronously
     * @param string $assetId - ID of asset to transfer
     * @param float $amount - Amount of asset to be transferred (expressed as a decimal amount)
     * @param array $receivingDevice - (optional, default: device that issues the request) A map (associative array),
     *                                  specifying the device Device to which the asset is to be transferred and that
     *                                  shall hold the total issued amount, with the following keys:
     *      'id' => [string]                  ID of receiving device. Should be a Catenis device ID unless
     *                                         isProdUniqueId is true
     *      'isProdUniqueId' => [boolean]     (optional, default: false) Indicates whether supplied ID is a product
     *                                         unique ID (otherwise, it should be a Catenis device ID)
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function transferAssetAsync($assetId, $amount, array $receivingDevice)
    {
        return $this->sendPostRequestAsync(...self::transferAssetRequestParams($assetId, $amount, $receivingDevice));
    }

    /**
     * Retrieve information about a given asset asynchronously
     * @param string $assetId - ID of asset to retrieve information
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function retrieveAssetInfoAsync($assetId)
    {
        return $this->sendGetRequestAsync(...self::retrieveAssetInfoRequestParams($assetId));
    }

    /**
     * Get the current balance of a given asset held by the device asynchronously
     * @param string $assetId - ID of asset to get balance
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function getAssetBalanceAsync($assetId)
    {
        return $this->sendGetRequestAsync(...self::getAssetBalanceRequestParams($assetId));
    }

    /**
     * List assets owned by the device asynchronously
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of
     *                          list) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listOwnedAssetsAsync($limit = null, $skip = null)
    {
        return $this->sendGetRequestAsync(...self::listOwnedAssetsRequestParams($limit, $skip));
    }

    /**
     * List assets issued by the device asynchronously
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of
     *                          list) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listIssuedAssetsAsync($limit = null, $skip = null)
    {
        return $this->sendGetRequestAsync(...self::listIssuedAssetsRequestParams($limit, $skip));
    }

    /**
     * Retrieve issuance history for a given asset asynchronously
     * @param string $assetId - ID of asset to retrieve issuance history
     * @param string|DateTime|null $startDate - (optional) Date and time specifying the lower boundary of the time
     *                                           frame within which the issuance events intended to be retrieved have
     *                                           occurred. The returned issuance events must have occurred not before
     *                                           that date/time. Note: if a string is passed, it should be an ISO 8601
     *                                           formatted date/time
     * @param string|DateTime|null $endDate - (optional) Date and time specifying the upper boundary of the time frame
     *                                         within which the issuance events intended to be retrieved have occurred.
     *                                         The returned issuance events must have occurred not after that date/
     *                                         time. Note: if a string is passed, it should be an ISO 8601 formatted
     *                                         date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of asset issuance events that should be returned
     * @param int|null $skip - (optional, default: 0) Number of asset issuance events that should be skipped (from
     *                          beginning of list of matching events) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function retrieveAssetIssuanceHistoryAsync(
        $assetId,
        $startDate = null,
        $endDate = null,
        $limit = null,
        $skip = null
    ) {
        return $this->sendGetRequestAsync(...self::retrieveAssetIssuanceHistoryRequestParams(
            $assetId,
            $startDate,
            $endDate,
            $limit,
            $skip
        ));
    }

    /**
     * List devices that currently hold any amount of a given asset asynchronously
     * @param string $assetId - ID of asset to get holders
     * @param int|null $limit - (optional, default: 500) Maximum number of list items that should be returned
     * @param int|null $skip - (optional, default: 0) Number of list items that should be skipped (from beginning of
     *                          list) and not returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listAssetHoldersAsync($assetId, $limit = null, $skip = null)
    {
        return $this->sendGetRequestAsync(...self::listAssetHoldersRequestParams($assetId, $limit, $skip));
    }

    /**
     * Export an asset to a foreign blockchain, by creating a new (ERC-20 compliant) token on that blockchain,
     *  asynchronously
     *
     * @param string $assetId - The ID of asset to export
     * @param string $foreignBlockchain - The key identifying the foreign blockchain. Valid options: 'ethereum',
     *                                     'binance', 'polygon'
     * @param array $token - A map (associative array) containing the following keys:
     *      'id' => [string]            The name of the token to be created on the foreign blockchain
     *      'symbol' => [string]        The symbol of the token to be created on the foreign blockchain
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'consumptionProfile' => [string]    (optional) Name of the foreign blockchain's native coin consumption
     *                                           profile to use. Valid options: 'fastest', 'fast', 'average', 'slow'
     *      'estimateOnly' => [bool]            (optional, default: false) When set, indicates that no asset export
     *                                           should be executed but only the estimated price (in the foreign
     *                                           blockchain's native coin) to fulfill the operation should be returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function exportAssetAsync($assetId, $foreignBlockchain, array $token, $options = null)
    {
        return $this->sendPostRequestAsync(...self::exportAssetRequestParams(
            $assetId,
            $foreignBlockchain,
            $token,
            $options
        ));
    }

    /**
     * Migrate an amount of a previously exported asset to/from the foreign blockchain token asynchronously
     *
     * @param string $assetId - The ID of the asset to migrate an amount of it
     * @param string $foreignBlockchain - The key identifying the foreign blockchain. Valid options: 'ethereum',
     *                                     'binance', 'polygon'
     * @param array|string $migration - A map (associative array) describing a new asset migration, with the keys listed
     *                                   below. Otherwise, if a string value is passed, it is assumed to be the ID of
     *                                   the asset migration to be reprocessed.
     *      'direction' => [string]         The direction of the migration. Valid options: 'outward', 'inward'
     *      'amount' => [float]             The amount (as a decimal value) of the asset to be migrated
     *      'destAddress' => [string]       (optional) The address of the account on the foreign blockchain that should
     *                                       be credited with the specified amount of the foreign token
     * @param array|null $options - (optional) A map (associative array) containing the following keys:
     *      'consumptionProfile' => [string]    (optional) Name of the foreign blockchain's native coin consumption
     *                                           profile to use. Valid options: 'fastest', 'fast', 'average', 'slow'
     *      'estimateOnly' => [bool]            (optional, default: false) When set, indicates that no asset migration
     *                                           should be executed but only the estimated price (in the foreign
     *                                           blockchain's native coin) to fulfill the operation should be returned
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function migrateAssetAsync($assetId, $foreignBlockchain, $migration, $options = null)
    {
        return $this->sendPostRequestAsync(...self::migrateAssetRequestParams(
            $assetId,
            $foreignBlockchain,
            $migration,
            $options
        ));
    }

    /**
     * Retrieve the current information about the outcome of an asset export asynchronously
     *
     * @param string $assetId - The ID of the asset that was exported
     * @param string $foreignBlockchain - The key identifying the foreign blockchain. Valid options: 'ethereum',
     *                                     'binance', 'polygon'
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function assetExportOutcomeAsync($assetId, $foreignBlockchain)
    {
        return $this->sendGetRequestAsync(...self::assetExportOutcomeRequestParams($assetId, $foreignBlockchain));
    }

    /**
     * Retrieve the current information about the outcome of an asset migration asynchronously
     *
     * @param string $migrationId - The ID of the asset migration
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function assetMigrationOutcomeAsync($migrationId)
    {
        return $this->sendGetRequestAsync(...self::assetMigrationOutcomeRequestParams($migrationId));
    }

    /**
     * Retrieves a list of issued asset exports that satisfy a given search criteria asynchronously
     *
     * @param array|null $selector - (optional) A map (associative array) containing the following keys:
     *      'assetId' => [string]               (optional) The ID of the exported asset
     *      'foreignBlockchain' => [string]     (optional) The key identifying the foreign blockchain to where the asset
     *                                           has been exported. Valid options: 'ethereum', 'binance', 'polygon'
     *      'tokenSymbol' => [string]           (optional) The symbol of the resulting foreign token
     *      'status' => [string]                (optional) A single status or a comma-separated list of statuses to
     *                                           include. Valid options: 'pending', 'success, 'error'
     *      'negateStatus' => [bool]            (optional, default: false) Boolean value indicating whether the
     *                                           specified statuses should be excluded instead
     *      'startDate' => [string|DateTime]    (optional) Date and time specifying the inclusive lower bound of the
     *                                           time frame within which the asset has been exported. If a string is
     *                                           passed, it should be an ISO 8601 formatted date/time
     *      'endDate' => [string:DateTime]      (optional) Date and time specifying the inclusive upper bound of the
     *                                           time frame within which the asset has been exported. If a string is
     *                                           passed, it should be an ISO 8601 formatted date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of asset exports that should be returned. Must
     *                           be a positive integer value not greater than 500
     * @param int|null $skip - (optional, default: 0) Number of asset exports that should be skipped (from beginning of
     *                          list of matching asset exports) and not returned. Must be a non-negative (includes
     *                          zero) integer value
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listExportedAssetsAsync($selector = null, $limit = null, $skip = null)
    {
        return $this->sendGetRequestAsync(...self::listExportedAssetsRequestParams($selector, $limit, $skip));
    }

    /**
     * Retrieves a list of issued asset migrations that satisfy a given search criteria asynchronously
     *
     * @param array|null $selector - (optional) A map (associative array) containing the following keys:
     *      'assetId' => [string]               (optional) The ID of the asset the amount of which has been migrated
     *      'foreignBlockchain' => [string]     (optional) The key identifying the foreign blockchain to/from where the
     *                                           asset amount has been migrated. Valid options: 'ethereum', 'binance',
     *                                           'polygon'
     *      'direction' => [string]             (optional) The direction of the migration. Valid options: 'outward',
     *                                           'inward'
     *      'status' => [string]                (optional) A single status or a comma-separated list of statuses to
     *                                           include. Valid options: 'pending', 'interrupted', 'success', 'error'
     *      'negateStatus' => [bool]            (optional, default: false) Boolean value indicating whether the
     *                                           specified statuses should be excluded instead
     *      'startDate' => [string|DateTime]    (optional) Date and time specifying the inclusive lower bound of the
     *                                           time frame within which the asset amount has been migrated. If a
     *                                           string is passed, it should be an ISO 8601 formatted date/time
     *      'endDate' => [string|DateTime]      (optional) Date and time specifying the inclusive upper bound of the
     *                                           time frame within which the asset amount has been migrated. If a
     *                                           string is passed, it should be an ISO 8601 formatted date/time
     * @param int|null $limit - (optional, default: 500) Maximum number of asset migrations that should be returned.
     *                           Must be a positive integer value not greater than 500
     * @param int|null $skip - (optional, default: 0) Number of asset migrations that should be skipped (from beginning
     *                          of list of matching asset migrations) and not returned. Must be a non-negative
     *                          (includes zero) integer value
     * @return PromiseInterface - A promise representing the asynchronous processing
     */
    public function listAssetMigrationsAsync($selector = null, $limit = null, $skip = null)
    {
        return $this->sendGetRequestAsync(...self::listAssetMigrationsRequestParams($selector, $limit, $skip));
    }
}
