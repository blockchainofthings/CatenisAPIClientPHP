<?php
/**
 * Created by claudio on 2020-01-08
 */

namespace Catenis\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

/**
 * Test cases for version 4.0.0 of Catenis API Client for PHP
 */
class PHPClientVer4d0d0Test extends TestCase
{
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '4c1749c8e86f65e0a73e5fb19f2aa9e74a716bc22d7956bf3072b4bc3fbfe2a0d138ad0d4bcfee251e'
        . '4e5f54d6e92b8fd4eb36958a7aeaeeb51e8d2fcc4552c3';
    protected static $device2 = [
        'id' => 'd8YpQ7jgPBJEkBrnvp58'
    ];
    protected static $accessKey2 = '267a687115b9752f2eec5be849b570b29133528f928868d811bad5e48e97a1d62d432bab44803586b2'
        . 'ac35002ec6f0eeaa98bec79b64f2f69b9cb0935b4df2c4';
    protected static $ctnClient1;
    protected static $ctnClient2;
    protected static $ctnClientAsync2;
    protected static $loop;

    public static function setUpBeforeClass(): void
    {
        echo "\nPHPClientVer4d0d0Test test class\n";

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
            'host' => 'localhost:3000',
            'secure' => false
        ]);

        self::$ctnClient2 = new ApiClient(self::$device2['id'], self::$accessKey2, [
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
     * Test logging a regular (non-off-chain) message to the blockchain
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogMessage()
    {
        $message = 'Test message #' . rand();

        $data = self::$ctnClient1->logMessage($message, [
            'offChain' => false
        ]);

        $this->assertTrue(isset($data->messageId));
        $this->assertRegExp('/^m\w{19}$/', $data->messageId);

        return [
            'message' => $message,
            'messageId' => $data->messageId
        ];
    }

    /**
     * Test retrieving container info of logged regular (non-off-chain) message
     *
     * @depends testLogMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testRetrieveInfoLoggedMessage(array $messageInfo)
    {
        $data = self::$ctnClient1->retrieveMessageContainer($messageInfo['messageId']);

        $this->assertThat($data, $this->logicalNot($this->objectHasAttribute('offChain')));
    }

    /**
     * Test reading logged regular (non-off-chain) message
     *
     * @depends testLogMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedMessage(array $messageInfo)
    {
        $data = self::$ctnClient1->readMessage($messageInfo['messageId']);

        $this->assertEquals($messageInfo['message'], $data->msgData);
    }

    /**
     * Test sending a regular (non-off-chain) message to another device and wait for message to be received
     *
     * @medium
     * @return array Info about the sent message
     */
    public function testSendMessage()
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
                // Send off-chain message and save returned message ID
                $messageId = self::$ctnClient1->sendMessage($message, self::$device2, ['offChain' => false])->messageId;
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data, &$wsNtfyChannel, &$messageId) {
            if (!is_null($messageId) && $retVal->messageId == $messageId) {
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
            $this->assertRegExp('/^m\w{19}$/', $messageId);

            return [
                'message' => $message,
                'messageId' => $messageId
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving container info of sent regular (non-off-chain) message
     *
     * @depends testSendMessage
     * @medium
     * @param array $messageInfo Info about the sent message
     * @return void
     */
    public function testRetrieveInfoSentMessage(array $messageInfo)
    {
        $data = self::$ctnClient1->retrieveMessageContainer($messageInfo['messageId']);

        $this->assertThat($data, $this->logicalNot($this->objectHasAttribute('offChain')));
    }

    /**
     * Test reading sent off-chain message
     *
     * @depends testSendMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadSentMessage(array $messageInfo)
    {
        $data = self::$ctnClient2->readMessage($messageInfo['messageId']);

        $this->assertEquals($messageInfo['message'], $data->msgData);
    }

    /**
     * Test logging an off-chain message to the blockchain
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogOffChainMessage()
    {
        $message = 'Test message #' . rand();

        $data = self::$ctnClient1->logMessage($message);

        $this->assertTrue(isset($data->messageId));
        $this->assertRegExp('/^o\w{19}$/', $data->messageId);

        return [
            'message' => $message,
            'messageId' => $data->messageId
        ];
    }

    /**
     * Test retrieving container info of logged off-chain message
     *
     * @depends testLogOffChainMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testRetrieveInfoLoggedOffChainMessage(array $messageInfo)
    {
        $data = self::$ctnClient1->retrieveMessageContainer($messageInfo['messageId']);

        $this->assertObjectHasAttribute('offChain', $data);
        $this->assertRegExp('/^Qm\w{44}$/', $data->offChain->cid);
    }

    /**
     * Test reading logged off-chain message
     *
     * @depends testLogOffChainMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedOffChainMessage(array $messageInfo)
    {
        $data = self::$ctnClient1->readMessage($messageInfo['messageId']);

        $this->assertEquals($messageInfo['message'], $data->msgData);
    }

    /**
     * Test sending an off-chain message to another device and wait for message to be received
     *
     * @medium
     * @return array Info about the sent message
     */
    public function testSendOffChainMessage()
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
                // Send off-chain message and save returned message ID
                $messageId = self::$ctnClient1->sendMessage($message, self::$device2)->messageId;
            } catch (Exception $ex) {
                // Get error and stop event loop
                $error = $ex;
                self::$loop->stop();
            }
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data, &$wsNtfyChannel, &$messageId) {
            if (!is_null($messageId) && $retVal->messageId == $messageId) {
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
            $this->assertRegExp('/^o\w{19}$/', $messageId);

            return [
                'message' => $message,
                'messageId' => $messageId
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving container info of sent off-chain message
     *
     * @depends testSendOffChainMessage
     * @medium
     * @param array $messageInfo Info about the sent message
     * @return void
     */
    public function testRetrieveInfoSentOffChainMessage(array $messageInfo)
    {
        $data = self::$ctnClient1->retrieveMessageContainer($messageInfo['messageId']);

        $this->assertObjectHasAttribute('offChain', $data);
        $this->assertRegExp('/^Qm\w{44}$/', $data->offChain->cid);
    }

    /**
     * Test reading sent off-chain message
     *
     * @depends testSendOffChainMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadSentOffChainMessage(array $messageInfo)
    {
        $data = self::$ctnClient2->readMessage($messageInfo['messageId']);

        $this->assertEquals($messageInfo['message'], $data->msgData);
    }
}
