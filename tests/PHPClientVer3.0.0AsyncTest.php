<?php
/**
 * Created by claudio on 2019-08-20
 */

namespace Catenis\Tests;

use Exception;
use DateTime;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

/**
 * Test cases for version 3.0.0 of Catenis API Client for PHP asynchronous methods
 */
class PHPClientVer3d0d0AsyncTest extends TestCase
{
    protected static $testStartDate;
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '4c1749c8e86f65e0a73e5fb19f2aa9e74a716bc22d7956bf3072b4bc3fbfe2a0d138ad0d4bcfee251e'
        . '4e5f54d6e92b8fd4eb36958a7aeaeeb51e8d2fcc4552c3';
    protected static $ctnClient1;
    protected static $ctnClientAsync1;
    protected static $loop;

    public static function setUpBeforeClass(): void
    {
        self::$testStartDate = new DateTime();

        echo "\nPHPClientVer3d0d0AsyncTest test class\n";

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

        // Instantiate (synchronous) Catenis API client
        self::$ctnClient1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false
        ]);

        // Instantiate event loop
        self::$loop = EventLoop\Factory::create();

        // Instantiate asynchronous Catenis API client
        self::$ctnClientAsync1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);
    }

    /**
     * Log a message to the blockchain
     *
     * @medium
     * @return void
     */
    public function testLogMessage()
    {
        $message = 'Test message #' . rand();

        $data = self::$ctnClient1->logMessage($message);

        $this->assertTrue(isset($data->messageId));
    }

    /**
     * Log a second message to the blockchain
     *
     * @medium
     * @return void
     */
    public function testLogOtherMessage()
    {
        $message = 'Test message #' . rand();

        $data = self::$ctnClient1->logMessage($message);

        $this->assertTrue(isset($data->messageId));
    }

    /**
     * Issue new asset
     *
     * @medium
     * @return array Info about the issued asset
     */
    public function testIssueAsset()
    {
        $assetName = 'Test asset #' . rand();

        $data = self::$ctnClient1->issueAsset([
            'name' => $assetName,
            'description' => 'Asset used for testing purpose',
            'canReissue' => true,
            'decimalPlaces' => 0
        ], 50);

        $this->assertTrue(isset($data->assetId));

        return [
            'assetName' => $assetName,
            'assetId' => $data->assetId
        ];
    }

    /**
     * Reissue asset
     *
     * @depends testIssueAsset
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testReissueAsset(array $assetInfo)
    {
        $data = self::$ctnClient1->reissueAsset($assetInfo['assetId'], 50);

        $this->assertEquals(100, $data->totalExistentBalance, 'Unexpected reported total issued asset amount');
    }
    /**
     * Test listing all messages
     *
     * @depends testLogMessage
     * @depends testLogOtherMessage
     * @large
     * @return void
     */
    public function testListAllMessagesAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listMessagesAsync([
            'startDate' => self::$testStartDate
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

        // Process result
        if (!is_null($data)) {
            $this->assertGreaterThanOrEqual(2, $data->msgCount);
            $this->assertFalse($data->hasMore);
        } else {
            throw $error;
        }
    }

    /**
     * Test listing messages retrieving only the first one
     *
     * @depends testLogMessage
     * @depends testLogOtherMessage
     * @large
     * @return void
     */
    public function testListFirstMessageAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listMessagesAsync([
            'startDate' => self::$testStartDate
        ], 1)->then(
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

        // Process result
        if (!is_null($data)) {
            $this->assertEquals(1, $data->msgCount);
            $this->assertTrue($data->hasMore);
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving all asset issuance events
     *
     * @depends testIssueAsset
     * @depends testReissueAsset
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testRetrieveAllAssetIssuanceEvents(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveAssetIssuanceHistoryAsync($assetInfo['assetId'])->then(
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

        // Process result
        if (!is_null($data)) {
            $this->assertEquals(2, count($data->issuanceEvents));
            $this->assertFalse($data->hasMore);
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving first asset issuance events
     *
     * @depends testIssueAsset
     * @depends testReissueAsset
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testRetrieveFirstAssetIssuanceEvents(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveAssetIssuanceHistoryAsync($assetInfo['assetId'], null, null, 1)->then(
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

        // Process result
        if (!is_null($data)) {
            $this->assertEquals(1, count($data->issuanceEvents));
            $this->assertTrue($data->hasMore);
        } else {
            throw $error;
        }
    }
}
