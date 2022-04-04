<?php
/**
 * Created by claudio on 2020-01-14
 */

namespace Catenis\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

if (empty($GLOBALS['__testEnv'])) {
    // Load default (development) test environment
    include_once __DIR__ . '/inc/DevTestEnv.php';
}

/**
 * Test cases for version 4.0.1 of Catenis API Client for PHP asynchronous methods
 */
class PHPClientVer4d0d1AsyncTest extends TestCase
{
    protected static $testEnv;
    protected static $device1;
    protected static $accessKey1;
    protected static $device2;
    protected static $accessKey2;
    protected static $ctnClientAsync1;
    protected static $ctnClientAsync2;
    protected static $loop;

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

        echo "\nPHPClientVer4d0d1AsyncTest test class\n";

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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->logMessageAsync($message, [
            'offChain' => false
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
            $this->assertTrue(isset($data->messageId));
            $this->assertRegExp('/^m\w{19}$/', $data->messageId);

            return [
                'message' => $message,
                'messageId' => $data->messageId,
            ];
        } else {
            throw $error;
        }
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveMessageContainerAsync($messageInfo['messageId'])->then(
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
            $this->assertThat($data, $this->logicalNot($this->objectHasAttribute('offChain')));
        } else {
            throw $error;
        }
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->readMessageAsync($messageInfo['messageId'])->then(
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
            $this->assertEquals($messageInfo['message'], $data->msgData);
        } else {
            throw $error;
        }
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
        
        $wsNtfyChannel->on('open', function () use (&$message, &$messageId, &$error, &$data, &$wsNtfyChannel) {
            // WebSocket notification channel successfully open and ready to send notifications.
            //  Send message from device #1 to device #2
            $message = 'Test message #' . rand();

            self::$ctnClientAsync1->sendMessageAsync($message, self::$device2, ['offChain' => false])->then(
                function ($data2) use (&$messageId, &$data, &$wsNtfyChannel) {
                    // Message successfully sent. Save message ID
                    $messageId = $data2->messageId;

                    if (!is_null($data)) {
                        // Notification already received. Check if it was for this message
                        if ($data->messageId == $messageId) {
                            // If so, close notification channel, and stop event loop
                            $wsNtfyChannel->close();
                            self::$loop->stop();
                        }
                    }
                },
                function ($ex) use (&$error) {
                    // Get error and stop event loop
                    $error = $ex;
                    self::$loop->stop();
                }
            );
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data, &$wsNtfyChannel, &$messageId) {
            // Notification received
            if (!is_null($messageId)) {
                // ID of sent message already returned. Check if this notification is for the correct message
                if ($retVal->messageId == $messageId) {
                    // If so, save returned data, close notification channel, and stop event loop
                    $data = $retVal;
                    $wsNtfyChannel->close();
                    self::$loop->stop();
                }
            } else {
                // ID of sent message not yet returned. Just save notification data
                $data = $retVal;
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveMessageContainerAsync($messageInfo['messageId'])->then(
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
            $this->assertThat($data, $this->logicalNot($this->objectHasAttribute('offChain')));
        } else {
            throw $error;
        }
    }

    /**
     * Test reading sent regular (non-off-chain) message
     *
     * @depends testSendMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadSentMessage(array $messageInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->readMessageAsync($messageInfo['messageId'])->then(
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
            $this->assertEquals($messageInfo['message'], $data->msgData);
        } else {
            throw $error;
        }
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->logMessageAsync($message)->then(
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
            $this->assertTrue(isset($data->messageId));
            $this->assertRegExp('/^o\w{19}$/', $data->messageId);

            return [
                'message' => $message,
                'messageId' => $data->messageId,
            ];
        } else {
            throw $error;
        }
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveMessageContainerAsync($messageInfo['messageId'])->then(
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
            $this->assertObjectHasAttribute('offChain', $data);
            $this->assertRegExp('/^Qm\w{44}$/', $data->offChain->cid);
        } else {
            throw $error;
        }
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->readMessageAsync($messageInfo['messageId'])->then(
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
            $this->assertEquals($messageInfo['message'], $data->msgData);
        } else {
            throw $error;
        }
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
        
        $wsNtfyChannel->on('open', function () use (&$message, &$messageId, &$error, &$data, &$wsNtfyChannel) {
            // WebSocket notification channel successfully open and ready to send notifications.
            //  Send message from device #1 to device #2
            $message = 'Test message #' . rand();

            self::$ctnClientAsync1->sendMessageAsync($message, self::$device2)->then(
                function ($data2) use (&$messageId, &$data, &$wsNtfyChannel) {
                    // Message successfully sent. Save message ID
                    $messageId = $data2->messageId;

                    if (!is_null($data)) {
                        // Notification already received. Check if it was for this message
                        if ($data->messageId == $messageId) {
                            // If so, close notification channel, and stop event loop
                            $wsNtfyChannel->close();
                            self::$loop->stop();
                        }
                    }
                },
                function ($ex) use (&$error) {
                    // Get error and stop event loop
                    $error = $ex;
                    self::$loop->stop();
                }
            );
        });
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data, &$wsNtfyChannel, &$messageId) {
            // Notification received
            if (!is_null($messageId)) {
                // ID of sent message already returned. Check if this notification is for the correct message
                if ($retVal->messageId == $messageId) {
                    // If so, save returned data, close notification channel, and stop event loop
                    $data = $retVal;
                    $wsNtfyChannel->close();
                    self::$loop->stop();
                }
            } else {
                // ID of sent message not yet returned. Just save notification data
                $data = $retVal;
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
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveMessageContainerAsync($messageInfo['messageId'])->then(
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
            $this->assertObjectHasAttribute('offChain', $data);
            $this->assertRegExp('/^Qm\w{44}$/', $data->offChain->cid);
        } else {
            throw $error;
        }
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
        $data = null;
        $error = null;

        self::$ctnClientAsync2->readMessageAsync($messageInfo['messageId'])->then(
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
            $this->assertEquals($messageInfo['message'], $data->msgData);
        } else {
            throw $error;
        }
    }
}
