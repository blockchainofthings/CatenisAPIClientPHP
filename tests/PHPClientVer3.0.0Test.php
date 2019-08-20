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
 * Test cases for version 3.0.0 of Catenis API Client for PHP
 */
class PHPClientVer3d0d0Test extends TestCase
{
    protected static $testStartDate;
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '544bca61a4116e15fd7bb7c3acb2eef2a1466635446e1aa3077b6a4931be51c4c620b87f1f8fdc3c729'
        . '1f9dc32eb52f3e008755d3ecdaa57052188702c3fee61';
    protected static $device2 = [
        'id' => 'd8YpQ7jgPBJEkBrnvp58'
    ];
    protected static $accessKey2 = '61281120a92dc6267af11170d161f64478b0a852f3cce4286b8a1b82afd2de7077472b6f7b93b6d5542'
        . '95d859815a37cb89f4f875b7aaeb0bd2babd9531c6883';
    protected static $ctnClient1;
    protected static $ctnClientAsync2;
    protected static $loop;

    public static function setUpBeforeClass(): void
    {
        self::$testStartDate = new DateTime();

        echo "\nPHPClientVer3d0d0Test test class\n";

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

        // Instantiate (synchronous) Catenis API client
        self::$ctnClient1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false
        ]);

        // Instantiate event loop
        self::$loop = EventLoop\Factory::create();

        // Instantiate asynchronous Catenis API client
        self::$ctnClientAsync2 = new ApiClient(self::$device2['id'], self::$accessKey2, [
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
     * Test receiving notification of new message received
     *
     * @large
     * @return void
     */
    public function testReceiveNewMessageNotification()
    {
        $wsNtfyChannel = self::$ctnClientAsync2->createWsNotifyChannel('new-msg-received');

        $data = null;
        $error = null;
        $message = null;
        $messageId = null;

        $wsNtfyChannel->on('error', function ($err) use (&$error) {
            // Get error and stop event loop
            $error = new Exception('Error in the underlying WebSocket connection: ' . $err);
            self::$loop->stop();
        });
        
        $wsNtfyChannel->on('close', function ($code, $reason) use (&$data, &$error) {
            if (is_null($data)) {
                // Get close reason and stop event loop
                $error = new Exception("WebSocket connection has been closed: [$code] $reason");
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('open', function () use (&$message, &$messageId, &$error) {
            // WebSocket notification channel successfully open and ready to send notifications.
            //  Send message from device #1 to device #2
            $message = 'Test message #' . rand();

            try {
                // Save message and save returned message ID
                $messageId = self::$ctnClient1->sendMessage($message, self::$device2)->messageId;
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data, &$wsNtfyChannel, &$messageId) {
            if (!is_null($messageId)) {
                // Notification received. Get returned data, close notification channel, and stop event loop
                $data = $retVal;
                $wsNtfyChannel->close();
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

        // Process result
        if (!is_null($data)) {
            $this->assertEquals($data->messageId, $messageId);

            return [
                'message' => $message,
                'messageId' => $messageId
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test listing all messages
     *
     * @depends testLogMessage
     * @depends testReceiveNewMessageNotification
     * @large
     * @return void
     */
    public function testListAllMessages()
    {
        $data = self::$ctnClient1->listMessages([
            'startDate' => self::$testStartDate
        ]);

        $this->assertGreaterThanOrEqual(2, $data->msgCount);
        $this->assertFalse($data->hasMore);
    }

    /**
     * Test listing messages retrieving only the first one
     *
     * @depends testLogMessage
     * @depends testReceiveNewMessageNotification
     * @large
     * @return void
     */
    public function testListFirstMessage()
    {
        $data = self::$ctnClient1->listMessages([
            'startDate' => self::$testStartDate
        ], 1);

        $this->assertEquals(1, $data->msgCount);
        $this->assertTrue($data->hasMore);
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
        $data = self::$ctnClient1->retrieveAssetIssuanceHistory($assetInfo['assetId']);

        $this->assertEquals(2, count($data->issuanceEvents));
        $this->assertFalse($data->hasMore);
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
        $data = self::$ctnClient1->retrieveAssetIssuanceHistory($assetInfo['assetId'], null, null, 1);

        $this->assertEquals(1, count($data->issuanceEvents));
        $this->assertTrue($data->hasMore);
    }
}
