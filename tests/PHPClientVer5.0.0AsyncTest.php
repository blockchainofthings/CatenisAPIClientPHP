<?php
/**
 * Created by claudio on 2021-08-18
 */

namespace Catenis\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

/**
 * Test cases for version 5.0.0 of Catenis API Client for PHP asynchronous methods
 */
class PHPClientVer5d0d0AsyncTest extends TestCase
{
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '4c1749c8e86f65e0a73e5fb19f2aa9e74a716bc22d7956bf3072b4bc3fbfe2a0d138ad0d4bcfee251e'
        . '4e5f54d6e92b8fd4eb36958a7aeaeeb51e8d2fcc4552c3';
    protected static $ctnClientAsync;
    protected static $loop;
    protected static $messages;
    protected static $asset;
    protected static $foreignBlockchain = 'ethereum';
    protected static $adminAddress = '0xe247c9BfDb17e7D8Ae60a744843ffAd19C784943';
    protected static $amountToMigrate = 24.75;

    public static function setUpBeforeClass(): void
    {
        echo "\nPHPClientVer5d0d0AsyncTest test class\n";

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

        // Instantiate event loop
        self::$loop = EventLoop\Factory::create();

        // Instantiate asynchronous Catenis API client
        self::$ctnClientAsync = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);

        $randomId = rand();

        // Issue test asset
        self::$asset = [
            'randomId' => $randomId,
            'info' => [
                'name' => 'Test_asset_#' . $randomId,
                'canReissue' => true,
                'decimalPlaces' => 2
            ],
            'token' => [
                'name' => 'Catenis test token #' . $randomId,
                'symbol' => 'CTK' . $randomId
            ]
        ];

        $data = null;
        $error = null;

        self::$ctnClientAsync->issueAssetAsync(self::$asset['info'], 200)->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Save ID of test asset
            self::$asset['id'] = $data->assetId;
        } else {
            // Throw error
            throw new Exception('Error issuing test asset.', 0, $error);
        }
    }

    /**
     * Test retrieving asset export price estimate
     *
     * @medium
     * @return void
     */
    public function testAssetExportPriceEstimate()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->exportAssetAsync(self::$asset['id'], self::$foreignBlockchain, self::$asset['token'], [
            'estimateOnly' => true
        ])->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertObjectHasAttribute('estimatedPrice', $data);
        } else {
            // Throw error
            throw new Exception('Error getting asset export price estimate.', 0, $error);
        }
    }

    /**
     * Test exporting asset and receiving notification of its final outcome
     *
     * @medium
     * @return void
     */
    public function testExportAssetAndOutcomeNotification()
    {
        // Create WebSocket notification channel to be notified when an asset export is finalized
        $wsNtfyChannel = self::$ctnClientAsync->createWsNotifyChannel('asset-export-outcome');

        $error = null;
        $ntfyChannelClosed = false;
        $foreignTxId = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$ntfyChannelClosed, &$error) {
            if (!$ntfyChannelClosed) {
                // Issue error and stop event loop
                $error = new Exception("WebSocket notification channel closed unexpectedly. [$code] - $reason");
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('open', function () use (&$wsNtfyChannel, &$ntfyChannelClosed, &$foreignTxId, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications.
            //  Export asset
            self::$ctnClientAsync->exportAssetAsync(
                self::$asset['id'],
                self::$foreignBlockchain,
                self::$asset['token']
            )->then(
                function ($data) use (&$wsNtfyChannel, &$ntfyChannelClosed, &$foreignTxId, &$error) {
                    // Validate asset export result
                    if (isset($data->foreignTransaction) && isset($data->foreignTransaction->txid)) {
                        // Save foreign transaction ID
                        $foreignTxId = $data->foreignTransaction->txid;

                        if (isset(self::$asset['tokenId'])) {
                            // Notification already received.
                            //  Close notification channel, and stop event loop
                            $ntfyChannelClosed = true;
                            $wsNtfyChannel->close();
                            self::$loop->stop();
                        }
                    } else {
                        // Issue error and stop event loop
                        $error = new Exception('Inconsistent export asset result: ' . print_r($data, true));
                        self::$loop->stop();
                    }
                },
                function ($ex) use (&$error) {
                    // Get returned error and stop event loop
                    $error = new Exception('Error exporting asset.', 0, $ex);
                    self::$loop->stop();
                }
            );
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (
            &$wsNtfyChannel,
            &$ntfyChannelClosed,
            &$foreignTxId,
            &$error
        ) {
            // Notification received. Make sure that it refers to the
            //  asset export that we are expecting
            if ($retVal->assetId === self::$asset['id'] && $retVal->foreignBlockchain === self::$foreignBlockchain) {
                if ($retVal->status === 'success') {
                    // Asset export succeeded. Save token ID
                    self::$asset['tokenId'] = $retVal->token->id;

                    if (!is_null($foreignTxId)) {
                        // Export asset call has already returned.
                        //  Close notification channel
                        $ntfyChannelClosed = true;
                        $wsNtfyChannel->close();
                    }
                } else {
                    // Issue error
                    $error = new Exception(
                        'Error executing foreign transaction to export asset: ' . $retVal->foreignTransaction->error
                    );
                }

                // Stop event loop
                self::$loop->stop();
            }
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = new Exception('Error opening WebSocket notification channel.', 0, $ex);
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Process result
        if (is_null($error)) {
            $this->assertTrue(isset(self::$asset['tokenId']));
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving asset export outcome
     *
     * @depends testExportAssetAndOutcomeNotification
     * @medium
     * @return void
     */
    public function testRetrieveAssetExportOutcome()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->assetExportOutcomeAsync(self::$asset['id'], self::$foreignBlockchain)->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('foreignTransaction'),
                    $this->objectHasAttribute('token'),
                    $this->objectHasAttribute('status'),
                    $this->objectHasAttribute('date')
                )
            );
            $this->assertEquals($data->status, 'success');
        } else {
            // Throw error
            throw new Exception('Error retrieving asset export outcome.', 0, $error);
        }
    }

    /**
     * Test retrieving asset migration price estimate
     *
     * @medium
     * @return void
     */
    public function testAssetMigrationPriceEstimate()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->migrateAssetAsync(self::$asset['id'], self::$foreignBlockchain, [
            'direction' => 'outward',
            'amount' => self::$amountToMigrate,
            'destAddress' => self::$adminAddress
        ], [
            'estimateOnly' => true
        ])->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertObjectHasAttribute('estimatedPrice', $data);
        } else {
            // Throw error
            throw new Exception('Error getting asset migration price estimate.', 0, $error);
        }
    }

    /**
     * Test migrating asset amount and receiving notification of its final outcome
     *
     * @medium
     * @return void
     */
    public function testMigrateAssetAndOutcomeNotification()
    {
        // Create WebSocket notification channel to be notified when an asset export is finalized
        $wsNtfyChannel = self::$ctnClientAsync->createWsNotifyChannel('asset-migration-outcome');

        $error = null;
        $ntfyChannelClosed = false;
        $ntfyData = [];
        $finalOutcome = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$ntfyChannelClosed, &$error) {
            if (!$ntfyChannelClosed) {
                // Issue error and stop event loop
                $error = new Exception("WebSocket notification channel closed unexpectedly. [$code] - $reason");
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('open', function () use (
            &$wsNtfyChannel,
            &$ntfyChannelClosed,
            &$ntfyData,
            &$finalOutcome,
            $error
        ) {
            // WebSocket notification channel successfully open and ready to send notifications
            self::$ctnClientAsync->migrateAssetAsync(self::$asset['id'], self::$foreignBlockchain, [
                'direction' => 'outward',
                'amount' => self::$amountToMigrate,
                'destAddress' => self::$adminAddress
            ])->then(
                function ($data) use (&$wsNtfyChannel, &$ntfyChannelClosed, &$ntfyData, &$finalOutcome, &$error) {
                    // Validate asset migration result
                    if (isset($data->migrationId)) {
                        // Save asset migration ID
                        self::$asset['migrationId'] = $data->migrationId;

                        // Check if notification has already been received, and
                        //  its data (final outcome) if so
                        foreach ($ntfyData as $data) {
                            if ($data->migrationId === self::$asset['migrationId']) {
                                $finalOutcome = $data;
                                break;
                            }
                        }

                        if (!is_null($finalOutcome)) {
                            // Notification already received.
                            //  Close notification channel, and stop event loop
                            $ntfyChannelClosed = true;
                            $wsNtfyChannel->close();
                            self::$loop->stop();
                        }
                    } else {
                        // Issue error and stop event loop
                        $error = new Exception('Inconsistent migrate asset result: ' . print_r($data, true));
                        self::$loop->stop();
                    }
                },
                function ($ex) use (&$error) {
                    // Get returned error and stop event loop
                    $error = new Exception('Error migrating asset.', 0, $ex);
                    self::$loop->stop();
                }
            );
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (
            &$wsNtfyChannel,
            &$ntfyChannelClosed,
            &$ntfyData,
            &$finalOutcome,
            &$error
        ) {
            // Notification received. Check if migrate asset call has already returned
            if (isset(self::$asset['migrationId'])) {
                // Make sure that notification refers to the asset migration that we are expecting
                if ($retVal->migrationId === self::$asset['migrationId']) {
                    // Save notification data (final outcome)
                    $finalOutcome = $retVal;

                    if ($retVal->status === 'success') {
                        // Asset migration succeeded.
                        //  Close notification channel
                        $ntfyChannelClosed = true;
                        $wsNtfyChannel->close();
                    } else {
                        // Issue error
                        $error = new Exception(
                            'Error executing foreign transaction to migrate asset amount: '
                            . $retVal->foreignTransaction->error
                        );
                    }

                    // Stop event looop
                    self::$loop->stop();
                }
            } else {
                // Migrate asset call has not returned yet.
                //  Save notification data
                $ntfyData[] = $retVal;
            }
        });
    
        $wsNtfyChannel->open()->then(
            function () {
                // WebSocket client successfully connected. Wait for open event
            },
            function (\Catenis\Exception\WsNotificationException $ex) use (&$error) {
                // Get error and stop event loop
                $error = new Exception('Error opening WebSocket notification channel.', 0, $ex);
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        // Process result
        if (is_null($error)) {
            $this->assertTrue(isset($finalOutcome));
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving asset migration outcome
     *
     * @depends testMigrateAssetAndOutcomeNotification
     * @medium
     * @return void
     */
    public function testRetrieveAssetMigrationOutcome()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->assetMigrationOutcomeAsync(self::$asset['migrationId'])->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('assetId'),
                    $this->objectHasAttribute('foreignBlockchain'),
                    $this->objectHasAttribute('direction'),
                    $this->objectHasAttribute('amount'),
                    $this->objectHasAttribute('catenisService'),
                    $this->objectHasAttribute('foreignTransaction'),
                    $this->objectHasAttribute('status'),
                    $this->objectHasAttribute('date')
                )
            );
            $this->assertEquals($data->status, 'success');
        } else {
            // Throw error
            throw new Exception('Error retrieving asset migration outcome.', 0, $error);
        }
    }

    /**
     * Test the fact that it should report the migrated asset amount
     *
     * @depends testMigrateAssetAndOutcomeNotification
     * @medium
     * @return void
     */
    public function testReportMigratedAssetAmount()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->listAssetHoldersAsync(self::$asset['id'])->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertObjectHasAttribute('assetHolders', $data);
            $this->assertEquals(count($data->assetHolders), 2);
            $this->assertTrue(isset($data->assetHolders[1]->migrated));
            $this->assertEquals($data->assetHolders[1]->balance->total, self::$amountToMigrate);
        } else {
            // Throw error
            throw new Exception('Error listing asset holders.', 0, $error);
        }
    }

    /**
     * Test listing exported assets
     *
     * @depends testExportAssetAndOutcomeNotification
     * @medium
     * @return void
     */
    public function testListExportedAssets()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->listExportedAssetsAsync([
            'assetId' => self::$asset['id'],
            'foreignBlockchain' => self::$foreignBlockchain,
            'status' => 'success'
        ])->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertObjectHasAttribute('exportedAssets', $data);
            $this->assertEquals(count($data->exportedAssets), 1);
            $this->assertThat(
                $data->exportedAssets[0],
                $this->logicalAnd(
                    $this->objectHasAttribute('assetId'),
                    $this->objectHasAttribute('foreignBlockchain'),
                    $this->objectHasAttribute('foreignTransaction'),
                    $this->objectHasAttribute('token'),
                    $this->objectHasAttribute('status'),
                    $this->objectHasAttribute('date')
                )
            );
            $this->assertEquals($data->exportedAssets[0]->assetId, self::$asset['id']);
            $this->assertEquals($data->exportedAssets[0]->foreignBlockchain, self::$foreignBlockchain);
            $this->assertEquals($data->exportedAssets[0]->status, 'success');
        } else {
            // Throw error
            throw new Exception('Error listing exported assets.', 0, $error);
        }
    }

    /**
     * Test listing asset migrations
     *
     * @depends testMigrateAssetAndOutcomeNotification
     * @medium
     * @return void
     */
    public function testListAssetMigrations()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync->listAssetMigrationsAsync([
            'assetId' => self::$asset['id'],
            'foreignBlockchain' => self::$foreignBlockchain,
            'direction' => 'outward',
            'status' => 'success'
        ])->then(
            function ($retVal) use (&$data) {
                // Get returned data and stop event loop
                $data = $retVal;
                self::$loop->stop();
            },
            function ($ex) use (&$error) {
                // Get returned error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        );

        // Start event loop
        self::$loop->run();

        if (!is_null($data)) {
            // Validate returned data
            $this->assertObjectHasAttribute('assetMigrations', $data);
            $this->assertEquals(count($data->assetMigrations), 1);
            $this->assertThat(
                $data->assetMigrations[0],
                $this->logicalAnd(
                    $this->objectHasAttribute('migrationId'),
                    $this->objectHasAttribute('assetId'),
                    $this->objectHasAttribute('foreignBlockchain'),
                    $this->objectHasAttribute('direction'),
                    $this->objectHasAttribute('amount'),
                    $this->objectHasAttribute('catenisService'),
                    $this->objectHasAttribute('foreignTransaction'),
                    $this->objectHasAttribute('status'),
                    $this->objectHasAttribute('date')
                )
            );
            $this->assertEquals($data->assetMigrations[0]->assetId, self::$asset['id']);
            $this->assertEquals($data->assetMigrations[0]->foreignBlockchain, self::$foreignBlockchain);
            $this->assertEquals($data->assetMigrations[0]->direction, 'outward');
            $this->assertEquals($data->assetMigrations[0]->amount, self::$amountToMigrate);
            $this->assertEquals($data->assetMigrations[0]->status, 'success');
        } else {
            // Throw error
            throw new Exception('Error listing asset migrations.', 0, $error);
        }
    }
}
