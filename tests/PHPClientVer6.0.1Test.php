<?php
/**
 * Created by claudio on 2022-09-22
 */

namespace Catenis\Tests;

use stdClass;
use Exception;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

if (empty($GLOBALS['__testEnv'])) {
    // Load default (development) test environment
    include_once __DIR__ . '/inc/DevTestEnv.php';
}

/**
 * Test cases for version 6.0.1 of Catenis API Client for PHP
 */
class PHPClientVer5d0d0Test extends TestCase
{
    protected static $testEnv;
    protected static $device1;
    protected static $accessKey1;
    protected static $device2;
    protected static $accessKey2;
    protected static $ctnClient1;
    protected static $ctnClient2;
    protected static $ctnClientAsync1;
    protected static $ctnClientAsync2;
    protected static $loop;
    protected static $sharedData;

    public static function setUpBeforeClass(): void
    {
        self::$testEnv = $GLOBALS['__testEnv'];
        self::$device1 = [
            'id' => self::$testEnv->device1->id
        ];
        self::$accessKey1 = self::$testEnv->device1->accessKey;
        self::$device2 = [
            'id' => self::$testEnv->device2->id
        ];
        self::$accessKey2 = self::$testEnv->device2->accessKey;

        echo "\nPHPClientVer6d0d1Test test class\n";

        echo 'Enter device #1 ID: [' . self::$device1['id'] . '] ';
        $id = rtrim(fgets(STDIN));

        if (!empty($id)) {
            self::$device1['id'] = $id;
        }

        echo 'Enter device #1 API access key: ';
        $key = rtrim(fgets(STDIN));

        if (!empty($key)) {
            self::$accessKey1 = $key;
        }

        echo 'Enter device #2 ID: [' . self::$device2['id'] . '] ';
        $id = rtrim(fgets(STDIN));

        if (!empty($id)) {
            self::$device2['id'] = $id;
        }

        echo 'Enter device #2 API access key: ';
        $key = rtrim(fgets(STDIN));

        if (!empty($key)) {
            self::$accessKey2 = $key;
        }

        // Instantiate (synchronous) Catenis API clients
        self::$ctnClient1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => self::$testEnv->host,
            'environment' => self::$testEnv->environment,
            'secure' => self::$testEnv->secure
        ]);

        self::$ctnClient2 = new ApiClient(self::$device2['id'], self::$accessKey2, [
            'host' => self::$testEnv->host,
            'environment' => self::$testEnv->environment,
            'secure' => self::$testEnv->secure
        ]);

        // Instantiate event loop
        self::$loop = EventLoop\Factory::create();

        // Instantiate asynchronous Catenis API clients
        self::$ctnClientAsync1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => self::$testEnv->host,
            'environment' => self::$testEnv->environment,
            'secure' => self::$testEnv->secure,
            'eventLoop' => self::$loop
        ]);

        self::$ctnClientAsync2 = new ApiClient(self::$device2['id'], self::$accessKey2, [
            'host' => self::$testEnv->host,
            'environment' => self::$testEnv->environment,
            'secure' => self::$testEnv->secure,
            'eventLoop' => self::$loop
        ]);

        self::$sharedData = new stdClass();
    }

    /**
     * Test issuance of non-fungible asset with single call and no isFinal argument
     *
     * @group assetIssuance
     * @return void
     */
    public function testIssueNFAssetSingleCallNoIsFinal()
    {
        $assetNumber = rand();

        $data = self::$ctnClient1->issueNonFungibleAsset([
            'assetInfo' => [
                'name' => 'TSTNFA#' . $assetNumber,
                'description' => 'Test non-fungible asset #' . $assetNumber,
                'canReissue' => true
            ]
        ], [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                    'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #1 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ],
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#2',
                    'description' => 'Test non-fungible token #2 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #2 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ],
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#3',
                    'description' => 'Test non-fungible token #3 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #3 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ],
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#4',
                    'description' => 'Test non-fungible token #4 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #4 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->assetId) && is_string($data->assetId)
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(4, $data->nfTokenIds);

        // Save new asset and non-fungible token IDs
        self::$sharedData->nfAsset = (object)[
            'number' => $assetNumber,
            'id' => $data->assetId
        ];
        self::$sharedData->nfTokenIds = $data->nfTokenIds;
    }

    /**
     * Test issuance of non-fungible asset with single call and isFinal argument
     *
     * @group assetIssuance
     * @return void
     */
    public function testIssueNFAssetSingleCallIsFinal()
    {
        $assetNumber = rand();

        $data = self::$ctnClient1->issueNonFungibleAsset([
            'assetInfo' => [
                'name' => 'TSTNFA#' . $assetNumber,
                'description' => 'Test non-fungible asset #' . $assetNumber,
                'canReissue' => true
            ]
        ], [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                    'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #1 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->assetId) && is_string($data->assetId)
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test issuance of non-fungible asset with multiple calls
     *
     * @group assetIssuance
     * @return void
     */
    public function testIssueNFAssetMultipleCalls()
    {
        $assetNumber = rand();

        // Initial call to issue non-fungible token
        $data = self::$ctnClient1->issueNonFungibleAsset([
            'assetInfo' => [
                'name' => 'TSTNFA#' . $assetNumber,
                'description' => 'Test non-fungible asset #' . $assetNumber,
                'canReissue' => true
            ]
        ], [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                    'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #1 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->continuationToken) && is_string($data->continuationToken)
            && !property_exists($data, 'assetIssuanceId')
            && !property_exists($data, 'assetId')
            && !property_exists($data, 'nfTokenIds')
        );

        // Continuation call to issue non-fungible token
        $data = self::$ctnClient1->issueNonFungibleAsset($data->continuationToken, [
            [
                'contents' => [
                    'data' => '. Continuation of contents for token #1 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->assetId) && is_string($data->assetId)
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test issuance of non-fungible asset with multiple calls and separate final call
     *
     * @group assetIssuance
     * @return void
     */
    public function testIssueNFAssetMultipleCallsSeparateFinal()
    {
        $assetNumber = rand();

        // Initial call to issue non-fungible token
        $data = self::$ctnClient1->issueNonFungibleAsset([
            'assetInfo' => [
                'name' => 'TSTNFA#' . $assetNumber,
                'description' => 'Test non-fungible asset #' . $assetNumber,
                'canReissue' => true
            ]
        ], [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                    'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #1 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->continuationToken) && is_string($data->continuationToken)
            && !property_exists($data, 'assetIssuanceId')
            && !property_exists($data, 'assetId')
            && !property_exists($data, 'nfTokenIds')
        );

        // Continuation call to issue non-fungible token
        $data = self::$ctnClient1->issueNonFungibleAsset($data->continuationToken, [
            [
                'contents' => [
                    'data' => '. Continuation of contents for token #1 of asset #' . $assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->continuationToken) && is_string($data->continuationToken)
            && !property_exists($data, 'assetIssuanceId')
            && !property_exists($data, 'assetId')
            && !property_exists($data, 'nfTokenIds')
        );

        // Final call to issue non-fungible token
        $data = self::$ctnClient1->issueNonFungibleAsset($data->continuationToken);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->assetId) && is_string($data->assetId)
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test notification of non-fungible token received when issuing non-fungible asset
     *
     * @group assetIssuance
     * @return void
     */
    public function testNFTokenReceivedNotifyNFAssetIssuance()
    {
        // Create WebSocket notification channel to be notified when non-fungible token is received
        $wsNtfyChannel = self::$ctnClientAsync2->createWsNotifyChannel('nf-token-received');

        $data = null;
        $error = null;
        /** @var array|null */
        $nfTokenIds = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$nfTokenIds, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            $assetNumber = rand();

            try {
                // Issue non-fungible asset and save the IDs of the non-fungible tokens that
                //  have been issued
                $nfTokenIds = self::$ctnClient1->issueNonFungibleAsset([
                    'assetInfo' => [
                        'name' => 'TSTNFA#' . $assetNumber,
                        'description' => 'Test non-fungible asset #' . $assetNumber,
                        'canReissue' => true
                    ],
                    'holdingDevices' => self::$device2
                ], [
                    [
                        'metadata' => [
                            'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                            'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                        ],
                        'contents' => [
                            'data' => 'Contents for token #1 of asset #' . $assetNumber,
                            'encoding' => 'utf8'
                        ]
                    ]
                ])->nfTokenIds;
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data) {
            // Notification received. Get returned data, and stop event loop
            $data = $retVal;
            self::$loop->stop();
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
                && isset($data->issuer) && ($data->issuer instanceof stdClass)
                && isset($data->from) && ($data->from instanceof stdClass)
            );
            $this->assertCount(count($nfTokenIds), $data->nfTokenIds);

            foreach ($data->nfTokenIds as $idx => $nfTokenId) {
                $this->assertEquals($nfTokenIds[$idx], $nfTokenId);
            }

            $this->assertEquals(self::$device1['id'], $data->issuer->deviceId);
            $this->assertEquals(self::$device1['id'], $data->from->deviceId);
        } else {
            throw $error;
        }
    }

    /**
     * Test notification of non-fungible asset issuance outcome for asset issuance
     *
     * @group assetIssuance
     * @return void
     */
    public function testNFAssetIssuanceOutcomedNotifyIssuance()
    {
        // Create WebSocket notification channel to be notified when asynchronous non-fungible
        //  asset issuance is finalized
        $wsNtfyChannel = self::$ctnClientAsync1->createWsNotifyChannel('nf-asset-issuance-outcome');

        $data = null;
        $error = null;
        $assetIssuanceId = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$assetIssuanceId, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            $assetNumber = rand();

            try {
                // Issue non-fungible asset asynchronously
                $data2 = self::$ctnClient1->issueNonFungibleAsset([
                    'assetInfo' => [
                        'name' => 'TSTNFA#' . $assetNumber,
                        'description' => 'Test non-fungible asset #' . $assetNumber,
                        'canReissue' => true
                    ],
                    'async' => true
                ], [
                    [
                        'metadata' => [
                            'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                            'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                        ],
                        'contents' => [
                            'data' => 'Contents for token #1 of asset #' . $assetNumber,
                            'encoding' => 'utf8'
                        ]
                    ]
                ]);

                if (($data2 instanceof stdClass) && isset($data2->assetIssuanceId)
                        && is_string($data2->assetIssuanceId)) {
                    // Save asset issuance ID
                    self::$sharedData->assetIssuanceId = $assetIssuanceId = $data2->assetIssuanceId;
                } else {
                    // Unexpected result. Issue error and stop event loop
                    $strData2 = print_r($data2, true);
                    $error = new Exception(
                        "Unexpected result for issuing non-fungible asset asynchronously: $strData2"
                    );
                    self::$loop->stop();
                }
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data) {
            // Notification received. Get returned data, and stop event loop
            $data = $retVal;
            self::$loop->stop();
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->assetIssuanceId) && is_string($data->assetIssuanceId)
                && isset($data->progress) && ($data->progress instanceof stdClass)
                && isset($data->result) && ($data->result instanceof stdClass)
                && isset($data->result->assetId) && is_string($data->result->assetId)
                && isset($data->result->nfTokenIds) && is_array($data->result->nfTokenIds)
            );
            $this->assertEquals($assetIssuanceId, $data->assetIssuanceId);
        } else {
            throw $error;
        }
    }

    /**
     * Sets up environment for non-fungible asset reissuance
     *
     * @group assetReissuance
     * @doesNotPerformAssertions
     * @return stdClass
     */
    public function testNFAssetReissuanceSetup()
    {
        $assetInfo = new stdClass();

        // Check if a new non-fungible asset needs to be issued
        if (!isset(self::$sharedData->nfAsset)) {
            // Issue new non-fungible asset
            $assetNumber = rand();

            $data = self::$ctnClient1->issueNonFungibleAsset([
                'assetInfo' => [
                    'name' => 'TSTNFA#' . $assetNumber,
                    'description' => 'Test non-fungible asset #' . $assetNumber,
                    'canReissue' => true
                ]
            ], [
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                        'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #1 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#2',
                        'description' => 'Test non-fungible token #2 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #2 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#3',
                        'description' => 'Test non-fungible token #3 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #3 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#4',
                        'description' => 'Test non-fungible token #4 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #4 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ]
            ]);

            // Save new asset and non-fungible token IDs
            self::$sharedData->nfAsset = (object)[
                'number' => $assetNumber,
                'id' => $data->assetId
            ];
            self::$sharedData->nfTokenIds = $data->nfTokenIds;

            // Save asset info
            $assetInfo->assetNumber = $assetNumber;
            $assetInfo->assetId = $data->assetId;
        } else {
            // Use existing non-fungible asset
            $assetInfo->assetNumber = self::$sharedData->nfAsset->number;
            $assetInfo->assetId = self::$sharedData->nfAsset->id;
        }

        return $assetInfo;
    }

    /**
     * Test reissuance of non-fungible asset with single call, no issuance info and no isFinal argument
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testReissueNFAssetSingleCallNoIssueInfoNoIsFinal(stdClass $assetInfo)
    {
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, null, [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#5',
                    'description' => 'Test non-fungible token #5 of test non-fungible asset #' . $assetInfo->assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #5 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test reissuance of non-fungible asset with single call, issuance info and no isFinal argument
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testReissueNFAssetSingleCallIssueInfoNoIsFinal(stdClass $assetInfo)
    {
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, [], [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#6',
                    'description' => 'Test non-fungible token #6 of test non-fungible asset #' . $assetInfo->assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #6 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test reissuance of non-fungible asset with single call, no issuance info and isFinal argument
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testReissueNFAssetSingleCallNoIssueInfoIsFinal(stdClass $assetInfo)
    {
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, null, [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#7',
                    'description' => 'Test non-fungible token #7 of test non-fungible asset #' . $assetInfo->assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #7 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], true);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test reissuance of non-fungible asset with multiple calls
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testReissueNFAssetMultipleCalls(stdClass $assetInfo)
    {
        // Initial call to issue non-fungible token
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, null, [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#8',
                    'description' => 'Test non-fungible token #8 of test non-fungible asset #' . $assetInfo->assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #8 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->continuationToken) && is_string($data->continuationToken)
            && !property_exists($data, 'assetIssuanceId')
            && !property_exists($data, 'nfTokenIds')
        );

        // Continuation call to issue non-fungible token
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, $data->continuationToken, [
            [
                'contents' => [
                    'data' => '. Continuation of contents for token #8 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test reissuance of non-fungible asset with multiple calls and separate final call
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testReissueNFAssetMultipleCalssSeparateFinal(stdClass $assetInfo)
    {
        // Initial call to issue non-fungible token
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, null, [
            [
                'metadata' => [
                    'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#9',
                    'description' => 'Test non-fungible token #9 of test non-fungible asset #' . $assetInfo->assetNumber
                ],
                'contents' => [
                    'data' => 'Contents for token #9 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->continuationToken) && is_string($data->continuationToken)
            && !property_exists($data, 'assetIssuanceId')
            && !property_exists($data, 'nfTokenIds')
        );

        // Continuation call to issue non-fungible token
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, $data->continuationToken, [
            [
                'contents' => [
                    'data' => '. Continuation of contents for token #8 of asset #' . $assetInfo->assetNumber,
                    'encoding' => 'utf8'
                ]
            ]
        ], false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->continuationToken) && is_string($data->continuationToken)
            && !property_exists($data, 'assetIssuanceId')
            && !property_exists($data, 'nfTokenIds')
        );

        // Final call to issue non-fungible token
        $data = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, $data->continuationToken);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'assetIssuanceId')
            && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
        );
        $this->assertCount(1, $data->nfTokenIds);
    }

    /**
     * Test notification of non-fungible token received when reissuing non-fungible asset
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testNFTokenReceivedNotifyNFAssetReissuance(stdClass $assetInfo)
    {
        // Create WebSocket notification channel to be notified when non-fungible token is received
        $wsNtfyChannel = self::$ctnClientAsync2->createWsNotifyChannel('nf-token-received');

        $data = null;
        $error = null;
        /** @var array|null */
        $nfTokenIds = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$assetInfo, &$nfTokenIds, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            try {
                // Reissue non-fungible asset and save the IDs of the non-fungible tokens that
                //  have been issued
                $nfTokenIds = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, [
                    'holdingDevices' => self::$device2
                ], [
                    [
                        'metadata' => [
                            'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#10',
                            'description' => 'Test non-fungible token #10 of test non-fungible asset #'
                                . $assetInfo->assetNumber
                        ],
                        'contents' => [
                            'data' => 'Contents for token #10 of asset #' . $assetInfo->assetNumber,
                            'encoding' => 'utf8'
                        ]
                    ]
                ])->nfTokenIds;
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data) {
            // Notification received. Get returned data, and stop event loop
            $data = $retVal;
            self::$loop->stop();
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
                && isset($data->issuer) && ($data->issuer instanceof stdClass)
                && isset($data->from) && ($data->from instanceof stdClass)
            );
            $this->assertCount(count($nfTokenIds), $data->nfTokenIds);

            foreach ($data->nfTokenIds as $idx => $nfTokenId) {
                $this->assertEquals($nfTokenIds[$idx], $nfTokenId);
            }

            $this->assertEquals(self::$device1['id'], $data->issuer->deviceId);
            $this->assertEquals(self::$device1['id'], $data->from->deviceId);
        } else {
            throw $error;
        }
    }

    /**
     * Test notification of non-fungible asset issuance outcome for asset reissuance
     *
     * @group assetReissuance
     * @depends testNFAssetReissuanceSetup
     * @param stdClass $assetInfo Info about the non-fungible asset to be reissued
     * @return void
     */
    public function testNFAssetIssuanceOutcomedNotifyReissuance(stdClass $assetInfo)
    {
        // Create WebSocket notification channel to be notified when asynchronous non-fungible
        //  asset issuance is finalized
        $wsNtfyChannel = self::$ctnClientAsync1->createWsNotifyChannel('nf-asset-issuance-outcome');

        $data = null;
        $error = null;
        $assetIssuanceId = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$assetInfo, &$assetIssuanceId, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            try {
                // Issue non-fungible asset asynchronously
                $data2 = self::$ctnClient1->reissueNonFungibleAsset($assetInfo->assetId, [
                    'async' => true
                ], [
                    [
                        'metadata' => [
                            'name' => 'TSTNFA#' . $assetInfo->assetNumber . '_NFT#11',
                            'description' => 'Test non-fungible token #11 of test non-fungible asset #'
                                . $assetInfo->assetNumber
                        ],
                        'contents' => [
                            'data' => 'Contents for token #11 of asset #' . $assetInfo->assetNumber,
                            'encoding' => 'utf8'
                        ]
                    ]
                ]);

                if (($data2 instanceof stdClass) && isset($data2->assetIssuanceId)
                        && is_string($data2->assetIssuanceId)) {
                    // Save asset issuance ID
                    $assetIssuanceId = $data2->assetIssuanceId;
                } else {
                    // Unexpected result. Issue error and stop event loop
                    $strData2 = print_r($data2, true);
                    $error = new Exception(
                        "Unexpected result for reissuing non-fungible asset asynchronously: $strData2"
                    );
                    self::$loop->stop();
                }
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data) {
            // Notification received. Get returned data, and stop event loop
            $data = $retVal;
            self::$loop->stop();
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->assetIssuanceId) && is_string($data->assetIssuanceId)
                && isset($data->assetId) && is_string($data->assetId)
                && isset($data->progress) && ($data->progress instanceof stdClass)
                && isset($data->result) && ($data->result instanceof stdClass)
                && isset($data->result->nfTokenIds) && is_array($data->result->nfTokenIds)
            );
            $this->assertEquals($assetIssuanceId, $data->assetIssuanceId);
            $this->assertEquals($assetInfo->assetId, $data->assetId);
        } else {
            throw $error;
        }
    }

    /**
     * Sets up environment for retrieving non-fungible asset issuance progress
     *
     * @group assetIssuanceProgress
     * @doesNotPerformAssertions
     * @return string
     */
    public function testNFAssetIssuanceProgressSetup()
    {
        $assetIssuanceId = null;

        // Check if a new non-fungible asset needs to be issued
        if (!isset(self::$sharedData->assetIssuanceId)) {
            // Issue new non-fungible asset asynchronously and save the asset issuance ID
            $assetNumber = rand();

            $assetIssuanceId = self::$ctnClient1->issueNonFungibleAsset([
                'assetInfo' => [
                    'name' => 'TSTNFA#' . $assetNumber,
                    'description' => 'Test non-fungible asset #' . $assetNumber,
                    'canReissue' => true
                ],
                'async' => true
            ], [
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                        'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #1 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ]
            ])->assetIssuanceId;
        } else {
            // Use existing asset issuance ID
            $assetIssuanceId = self::$sharedData->assetIssuanceId;
        }

        return $assetIssuanceId;
    }

    /**
     * Test retrieval of non-fungible asset issuance progress
     *
     * @group assetIssuanceProgress
     * @depends testNFAssetIssuanceProgressSetup
     * @param string $assetIssuanceId Asset issuance ID
     * @return void
     */
    public function testRetrieveNFAssetIssuanceProgress($assetIssuanceId)
    {
        $data = self::$ctnClient1->retrieveNonFungibleAssetIssuanceProgress($assetIssuanceId);

        $this->assertTrue(
            ($data instanceof stdClass)
            && (!property_exists($data, 'assetId') || is_string($data->assetId))
            && isset($data->progress) && ($data->progress instanceof stdClass)
            && (!property_exists($data, 'result') || ($data->result instanceof stdClass))
        );
    }

    /**
     * Sets up environment for non-fungible token retrieval
     *
     * @group tokenRetrieval
     * @doesNotPerformAssertions
     * @return string
     */
    public function testNFTokenRetrievalSetup()
    {
        $nfTokenId = null;

        // Check if a new non-fungible asset needs to be issued
        if (!isset(self::$sharedData->nfTokenIds)) {
            // Issue new non-fungible asset
            $assetNumber = rand();

            $data = self::$ctnClient1->issueNonFungibleAsset([
                'assetInfo' => [
                    'name' => 'TSTNFA#' . $assetNumber,
                    'description' => 'Test non-fungible asset #' . $assetNumber,
                    'canReissue' => true
                ]
            ], [
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                        'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #1 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#2',
                        'description' => 'Test non-fungible token #2 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #2 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#3',
                        'description' => 'Test non-fungible token #3 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #3 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#4',
                        'description' => 'Test non-fungible token #4 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #4 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ]
            ]);

            // Save non-fungible token IDs
            self::$sharedData->nfTokenIds = $data->nfTokenIds;

            $nfTokenId = $data->nfTokenIds[0];
        } else {
            // Use existing non-fungible token
            $nfTokenId = self::$sharedData->nfTokenIds[0];
        }

        return $nfTokenId;
    }

    /**
     * Test retrieval of non-fungible token with no options
     *
     * @group tokenRetrieval
     * @depends testNFTokenRetrievalSetup
     * @param string $nfTokenId ID of non-fungible token to retrieve
     * @return void
     */
    public function testRetrieveNFTokenNoOptions($nfTokenId)
    {
        $data = self::$ctnClient1->retrieveNonFungibleToken($nfTokenId);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'tokenRetrievalId')
            && isset($data->nonFungibleToken) && ($data->nonFungibleToken instanceof stdClass)
        );
    }

    /**
     * Test retrieval of non-fungible token with options
     *
     * @group tokenRetrieval
     * @depends testNFTokenRetrievalSetup
     * @param string $nfTokenId ID of non-fungible token to retrieve
     * @return void
     */
    public function testRetrieveNFTokenOptions($nfTokenId)
    {
        $data = self::$ctnClient1->retrieveNonFungibleToken($nfTokenId, [
            'retrieveContents' => false
        ]);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'continuationToken')
            && !property_exists($data, 'tokenRetrievalId')
            && isset($data->nonFungibleToken) && ($data->nonFungibleToken instanceof stdClass)
        );
    }

    /**
     * Test notification of non-fungible token retrieval outcome
     *
     * @group tokenRetrieval
     * @depends testNFTokenRetrievalSetup
     * @param string $nfTokenId ID of non-fungible token to retrieve
     * @return void
     */
    public function testNFTokenRetrievalOutcomedNotify($nfTokenId)
    {
        // Create WebSocket notification channel to be notified when asynchronous non-fungible
        //  token retrieval is finalized
        $wsNtfyChannel = self::$ctnClientAsync1->createWsNotifyChannel('nf-token-retrieval-outcome');

        $data = null;
        $error = null;
        $tokenRetrievalId = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$nfTokenId, &$tokenRetrievalId, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            try {
                // Retrieve non-fungible token asynchronously
                $data2 = self::$ctnClient1->retrieveNonFungibleToken($nfTokenId, [
                    'async' => true
                ]);

                if (($data2 instanceof stdClass) && isset($data2->tokenRetrievalId)
                        && is_string($data2->tokenRetrievalId)) {
                    // Save token retrieval ID
                    self::$sharedData->tokenRetrievalId = $tokenRetrievalId = $data2->tokenRetrievalId;
                } else {
                    // Unexpected result. Issue error and stop event loop
                    $strData2 = print_r($data2, true);
                    $error = new Exception(
                        "Unexpected result for retrieving non-fungible token asynchronously: $strData2"
                    );
                    self::$loop->stop();
                }
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data) {
            // Notification received. Get returned data, and stop event loop
            $data = $retVal;
            self::$loop->stop();
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->nfTokenId) && is_string($data->nfTokenId)
                && isset($data->tokenRetrievalId) && is_string($data->tokenRetrievalId)
                && isset($data->progress) && ($data->progress instanceof stdClass)
                && (!property_exists($data, 'continuationToken') || is_string($data->continuationToken))
            );
            $this->assertEquals($nfTokenId, $data->nfTokenId);
            $this->assertEquals($tokenRetrievalId, $data->tokenRetrievalId);
        } else {
            throw $error;
        }
    }

    /**
     * Sets up environment for retrieving non-fungible token retrieval progress
     *
     * @group tokenRetrievalProgress
     * @doesNotPerformAssertions
     * @return stdClass
     */
    public function testNFTokenRetrievalProgressSetup()
    {
        $tokenInfo = new stdClass();

        // Check if a new non-fungible asset needs to be issued
        if (!isset(self::$sharedData->tokenRetrievalId)) {
            // Issue new non-fungible asset
            $assetNumber = rand();

            $data = self::$ctnClient1->issueNonFungibleAsset([
                'assetInfo' => [
                    'name' => 'TSTNFA#' . $assetNumber,
                    'description' => 'Test non-fungible asset #' . $assetNumber,
                    'canReissue' => true
                ]
            ], [
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                        'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #1 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#2',
                        'description' => 'Test non-fungible token #2 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #2 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#3',
                        'description' => 'Test non-fungible token #3 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #3 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#4',
                        'description' => 'Test non-fungible token #4 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #4 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ]
            ]);

            // Save non-fungible token IDs
            self::$sharedData->nfTokenIds = $data->nfTokenIds;

            $tokenInfo->tokenId = $data->nfTokenIds[0];

            // Retrieve non-fungible token asynchronously and save the token retrieval ID
            $tokenInfo->tokenRetrievalId = self::$ctnClient1->retrieveNonFungibleToken($tokenInfo->tokenId, [
                'async' => true
            ])->tokenRetrievalId;
        } else {
            // Use existing token retrieval ID
            $tokenInfo->tokenId = self::$sharedData->nfTokenIds[0];
            $tokenInfo->tokenRetrievalId = self::$sharedData->tokenRetrievalId;
        }

        return $tokenInfo;
    }

    /**
     * Test retrieval of non-fungible token retrieval progress
     *
     * @group tokenRetrievalProgress
     * @depends testNFTokenRetrievalProgressSetup
     * @param stdClass $tokenInfo Non-fungible token info
     * @return void
     */
    public function testRetrieveNFTokenRetrievalProgress(stdClass $tokenInfo)
    {
        $data = self::$ctnClient1->retrieveNonFungibleTokenRetrievalProgress(
            $tokenInfo->tokenId,
            $tokenInfo->tokenRetrievalId
        );

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->progress) && ($data->progress instanceof stdClass)
            && (!property_exists($data, 'continuationToken') || is_string($data->continuationToken))
        );
    }

    /**
     * Sets up environment for non-fungible token transfer
     *
     * @group tokenTransfer
     * @doesNotPerformAssertions
     * @return array
     */
    public function testNFTokenTransferSetup()
    {
        $nfTokenIds = null;

        // Check if a new non-fungible asset needs to be issued
        if (!isset(self::$sharedData->nfTokenIds)) {
            // Issue new non-fungible asset
            $assetNumber = rand();

            $data = self::$ctnClient1->issueNonFungibleAsset([
                'assetInfo' => [
                    'name' => 'TSTNFA#' . $assetNumber,
                    'description' => 'Test non-fungible asset #' . $assetNumber,
                    'canReissue' => true
                ]
            ], [
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                        'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #1 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#2',
                        'description' => 'Test non-fungible token #2 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #2 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#3',
                        'description' => 'Test non-fungible token #3 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #3 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#4',
                        'description' => 'Test non-fungible token #4 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #4 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ]
            ]);

            // Save non-fungible token IDs
            self::$sharedData->nfTokenIds = $nfTokenIds = $data->nfTokenIds;
        } else {
            // Use existing non-fungible tokens
            $nfTokenIds = self::$sharedData->nfTokenIds;
        }

        return $nfTokenIds;
    }

    /**
     * Test transfer of non-fungible token with no async argument
     *
     * @group tokenTransfer
     * @depends testNFTokenTransferSetup
     * @param array $nfTokenIds Non-fungible token IDs
     * @return void
     */
    public function testTransferNFTokenNoAsyncArg(array $nfTokenIds)
    {
        $data = self::$ctnClient1->transferNonFungibleToken($nfTokenIds[0], self::$device2);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'tokenTransferId')
            && isset($data->success) && $data->success === true
        );
    }

    /**
     * Test transfer of non-fungible token with async argument
     *
     * @group tokenTransfer
     * @depends testNFTokenTransferSetup
     * @param array $nfTokenIds Non-fungible token IDs
     * @return void
     */
    public function testTransferNFTokenAsyncArg(array $nfTokenIds)
    {
        $data = self::$ctnClient1->transferNonFungibleToken($nfTokenIds[1], self::$device2, false);

        $this->assertTrue(
            ($data instanceof stdClass)
            && !property_exists($data, 'tokenTransferId')
            && isset($data->success) && $data->success === true
        );
    }

    /**
     * Test notification of non-fungible token received when transferring non-fungible token
     *
     * @group tokenTransfer
     * @depends testNFTokenTransferSetup
     * @param array $nfTokenIds Non-fungible token IDs
     * @return void
     */
    public function testNFTokenReceivedNotifyNFTokenTransfer(array $nfTokenIds)
    {
        // Create WebSocket notification channel to be notified when non-fungible token is received
        $wsNtfyChannel = self::$ctnClientAsync2->createWsNotifyChannel('nf-token-received');

        $data = null;
        $error = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$nfTokenIds, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            try {
                // Transfer non-fungible token
                self::$ctnClient1->transferNonFungibleToken($nfTokenIds[2], self::$device2);
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$nfTokenIds, &$data) {
            // Notification received. Make sure that this is for the non-fungible token
            //  that we have just transferred
            if (($retVal instanceof stdClass) && isset($retVal->nfTokenIds) && is_array($retVal->nfTokenIds)
                    && count($retVal->nfTokenIds) === 1 && $retVal->nfTokenIds[0] == $nfTokenIds[2]) {
                // Get returned data, and stop event loop
                $data = $retVal;
                self::$loop->stop();
            }
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->nfTokenIds) && is_array($data->nfTokenIds)
                && isset($data->issuer) && ($data->issuer instanceof stdClass)
                && isset($data->from) && ($data->from instanceof stdClass)
            );
            $this->assertCount(1, $data->nfTokenIds);
            $this->assertEquals(self::$device1['id'], $data->issuer->deviceId);
            $this->assertEquals(self::$device1['id'], $data->from->deviceId);
        } else {
            throw $error;
        }
    }

    /**
     * Test notification of non-fungible token transfer outcome
     *
     * @group tokenTransfer
     * @depends testNFTokenTransferSetup
     * @param array $nfTokenIds Non-fungible token IDs
     * @return void
     */
    public function testNFTokenTransferOutcomedNotify(array $nfTokenIds)
    {
        // Create WebSocket notification channel to be notified when asynchronous non-fungible
        //  token transfer is finalized
        $wsNtfyChannel = self::$ctnClientAsync1->createWsNotifyChannel('nf-token-transfer-outcome');

        $data = null;
        $error = null;
        $tokenTransferId = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            // Get close reason and stop event loop
            $error = new Exception("WebSocket connection has been closed: [$code] $reason");
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('open', function () use (&$nfTokenIds, &$tokenTransferId, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications
            try {
                // Transfer non-fungible token asynchronously
                $data2 = self::$ctnClient1->transferNonFungibleToken($nfTokenIds[3], self::$device2, true);

                if (($data2 instanceof stdClass) && isset($data2->tokenTransferId)
                        && is_string($data2->tokenTransferId)) {
                    // Save token transfer ID
                    self::$sharedData->tokenTransferId = $tokenTransferId = $data2->tokenTransferId;
                } else {
                    // Unexpected result. Issue error and stop event loop
                    $strData2 = print_r($data2, true);
                    $error = new Exception(
                        "Unexpected result for transferring non-fungible token asynchronously: $strData2"
                    );
                    self::$loop->stop();
                }
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data) {
            // Notification received. Get returned data, and stop event loop
            $data = $retVal;
            self::$loop->stop();
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Disable notification
        $wsNtfyChannel->removeAllListeners();
        $wsNtfyChannel->close();

        // Process result
        if ($data !== null) {
            $this->assertTrue(
                ($data instanceof stdClass)
                && isset($data->nfTokenId) && is_string($data->nfTokenId)
                && isset($data->tokenTransferId) && is_string($data->tokenTransferId)
                && isset($data->progress) && ($data->progress instanceof stdClass)
            );
            $this->assertEquals($nfTokenIds[3], $data->nfTokenId);
            $this->assertEquals($tokenTransferId, $data->tokenTransferId);
        } else {
            throw $error;
        }
    }

    /**
     * Sets up environment for retrieving non-fungible token transfer progress
     *
     * @group tokenTransferProgress
     * @doesNotPerformAssertions
     * @return stdClass
     */
    public function testNFTokenTransferProgressSetup()
    {
        $tokenInfo = new stdClass();

        // Check if a new non-fungible asset needs to be issued
        if (!isset(self::$sharedData->tokenTransferId)) {
            // Issue new non-fungible asset
            $assetNumber = rand();

            $data = self::$ctnClient1->issueNonFungibleAsset([
                'assetInfo' => [
                    'name' => 'TSTNFA#' . $assetNumber,
                    'description' => 'Test non-fungible asset #' . $assetNumber,
                    'canReissue' => true
                ]
            ], [
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#1',
                        'description' => 'Test non-fungible token #1 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #1 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#2',
                        'description' => 'Test non-fungible token #2 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #2 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#3',
                        'description' => 'Test non-fungible token #3 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #3 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ],
                [
                    'metadata' => [
                        'name' => 'TSTNFA#' . $assetNumber . '_NFT#4',
                        'description' => 'Test non-fungible token #4 of test non-fungible asset #' . $assetNumber
                    ],
                    'contents' => [
                        'data' => 'Contents for token #4 of asset #' . $assetNumber,
                        'encoding' => 'utf8'
                    ]
                ]
            ]);

            // Save non-fungible token IDs
            self::$sharedData->nfTokenIds = $data->nfTokenIds;

            $tokenInfo->tokenId = $data->nfTokenIds[3];

            // Transfer non-fungible token asynchronously and save the token transfer ID
            $tokenInfo->tokenTransferId = self::$ctnClient1->transferNonFungibleToken(
                $tokenInfo->tokenId,
                self::$device2,
                true
            )->tokenTransferId;
        } else {
            // Use existing token transfer ID
            $tokenInfo->tokenId = self::$sharedData->nfTokenIds[3];
            $tokenInfo->tokenTransferId = self::$sharedData->tokenTransferId;
        }

        return $tokenInfo;
    }

    /**
     * Test retrieval of non-fungible token transfer progress
     *
     * @group tokenTransferProgress
     * @depends testNFTokenTransferProgressSetup
     * @param stdClass $tokenInfo Non-fungible token info
     * @return void
     */
    public function testRetrieveNFTokenTransferProgress(stdClass $tokenInfo)
    {
        $data = self::$ctnClient1->retrieveNonFungibleTokenTransferProgress(
            $tokenInfo->tokenId,
            $tokenInfo->tokenTransferId
        );

        $this->assertTrue(
            ($data instanceof stdClass)
            && isset($data->progress) && ($data->progress instanceof stdClass)
        );
    }
}
