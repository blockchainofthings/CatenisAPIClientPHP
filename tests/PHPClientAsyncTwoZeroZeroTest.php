<?php
/**
 * Created by claudio on 2019-04-02
 */

namespace Catenis\Tests;

use Exception;
use DateTime;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

/**
 * Test cases for Catenis API Client for PHP asynchronous methods (ver. 2.0.0)
 */
class PHPClientAsyncTwoZeroZeroTest extends TestCase
{
    protected static $testStartDate;
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $device2 = [
        'id' => 'd8YpQ7jgPBJEkBrnvp58'
    ];
    protected static $ctnClient1;
    protected static $ctnClient2;
    protected static $ctnClientAsync1;
    protected static $ctnClientAsync2;
    protected static $loop;

    public static function setUpBeforeClass()
    {
        self::$testStartDate = new DateTime();

        echo "\nPHPClientAsyncTwoZeroZeroTest test class\n";

        echo 'Enter device #1 ID: [' . self::$device1['id'] . '] ';
        $id = rtrim(fgets(STDIN));

        if (!empty($id)) {
            self::$device1['id'] = $id;
        }

        echo 'Enter device #1 API access key: ';
        $accessKey1 = rtrim(fgets(STDIN));

        echo 'Enter device #2 ID: [' . self::$device2['id'] . '] ';
        $id = rtrim(fgets(STDIN));

        if (!empty($id)) {
            self::$device2['id'] = $id;
        }

        echo 'Enter device #2 API access key: ';
        $accessKey2 = rtrim(fgets(STDIN));

        // Instantiate (synchronous) Catenis API clients
        self::$ctnClient1 = new ApiClient(self::$device1['id'], $accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false
        ]);

        self::$ctnClient2 = new ApiClient(self::$device2['id'], $accessKey2, [
            'host' => 'localhost:3000',
            'secure' => false
        ]);

        // Instantiate event loop
        self::$loop = EventLoop\Factory::create();

        // Instantiate asynchronous Catenis API clients
        self::$ctnClientAsync1 = new ApiClient(self::$device1['id'], $accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);

        self::$ctnClientAsync2 = new ApiClient(self::$device2['id'], $accessKey2, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);
    }

    /*
     * Tests for asynchronous versions of API client methods
     */

    /**
     * Test logging a message to the blockchain asynchronously
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogMessageAsync()
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

            return [
                'message' => $message,
                'messageId' => $data->messageId,
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test logging a message in chunks to the blockchain asynchronously
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogMessageInChunksAsync()
    {
        $data = null;
        $error = null;
        $message = [
            'Test message #' . rand() . ' (part #1)',
            "\nTest message #" . rand() . ' (part #2)'
        ];

        // Pass part #1 of message
        self::$ctnClientAsync1->logMessageAsync([
            'data' => $message[0],
            'isFinal' => false
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
            $this->assertTrue(isset($data->continuationToken));
        } else {
            throw $error;
        }

        // Pass final part (#2) of message
        self::$ctnClientAsync1->logMessageAsync([
            'data' => $message[1],
            'isFinal' => true,
            'continuationToken' => $data->continuationToken
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

            return [
                'message' => implode('', $message),
                'messageId' => $data->messageId,
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test asynchronously logging a message to the blockchain asynchronously
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testAsyncLogMessageAsync()
    {
        $data = null;
        $error = null;
        $message = 'Test message #' . rand();

        self::$ctnClientAsync1->logMessageAsync($message, [
            'async' => true
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
            $this->assertTrue(isset($data->provisionalMessageId));

            return [
                'message' => $message,
                'ephemeralMessageId' => $data->provisionalMessageId,
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test sending a message to another device in chunks asynchronously
     *
     * @medium
     * @return void
     */
    public function testSendMessageInChunksAsync()
    {
        $data = null;
        $error = null;
        $message = [
            'Test message #' . rand() . ' (part #1)',
            "\nTest message #" . rand() . ' (part #2)'
        ];

        self::$ctnClientAsync1->sendMessageAsync([
            'data' => $message[0],
            'isFinal' => false
        ], self::$device2)->then(
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
            $this->assertTrue(isset($data->continuationToken));

            // Pass final part (#2) of message
            $data = self::$ctnClient1->sendMessage([
                'data' => $message[1],
                'isFinal' => true,
                'continuationToken' => $data->continuationToken
            ], self::$device2);
    
            $this->assertTrue(isset($data->messageId));
        } else {
            throw $error;
        }
    }

    /**
     * Test asynchronously sending a message to another device
     *
     * @medium
     * @return avoid
     */
    public function testAsyncSendMessageAsync()
    {
        $data = null;
        $error = null;
        $message = 'Test message #' . rand();

        self::$ctnClientAsync1->sendMessageAsync($message, self::$device2, [
            'async' => true
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
            $this->assertTrue(isset($data->provisionalMessageId));
        } else {
            throw $error;
        }
    }

    /**
     * Test reading a message that had been logged asynchronously
     *
     * @depends testLogMessageAsync
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedMessageAsync(array $messageInfo)
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
     * Test reading a message (that had been logged) in chunks asynchronously
     *
     * @depends testLogMessageInChunksAsync
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedMessageInChunksAsync(array $messageInfo)
    {
        $data = null;
        $error = null;
        $readMessage = [];

        // Read first part of message
        self::$ctnClientAsync1->readMessageAsync($messageInfo['messageId'], [
            'encoding' => 'utf8',
            'dataChunkSize' => (int)(strlen($messageInfo['message']) / 2) + 1
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('msgInfo'),
                    $this->objectHasAttribute('msgData'),
                    $this->objectHasAttribute('continuationToken')
                )
            );
    
            $readMessage[] = $data->msgData;
        } else {
            throw $error;
        }

        // Read final part of message
        $opts = [
            'encoding' => 'utf8',
            'continuationToken' => $data->continuationToken
        ];
        $data = null;

        self::$ctnClientAsync1->readMessageAsync($messageInfo['messageId'], $opts)->then(
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('msgData'),
                    $this->logicalNot($this->objectHasAttribute('continuationToken'))
                )
            );
    
            $readMessage[] = $data->msgData;
    
            $this->assertEquals($messageInfo['message'], implode('', $readMessage));
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving message container asynchronously
     *
     * @depends testLogMessageAsync
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testRetrieveMessageContainerAsync(array $messageInfo)
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
            $this->assertObjectHasAttribute('blockchain', $data, 'Inconsistent data returned for message container');
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving message progress asynchronously
     *
     * @depends testAsyncLogMessageAsync
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testRetrieveMessageProgressAsync(array $messageInfo)
    {
        $doAsyncProcess = function ($doAsyncProcess) use ($messageInfo) {
            $data = null;
            $error = null;
    
            self::$ctnClientAsync1->retrieveMessageProgressAsync($messageInfo['ephemeralMessageId'])->then(
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
                $this->assertThat(
                    $data,
                    $this->logicalAnd(
                        $this->objectHasAttribute('action'),
                        $this->objectHasAttribute('progress'),
                        $this->attribute(
                            $this->objectHasAttribute('done'),
                            'progress'
                        )
                    )
                );

                if (!$data->progress->done) {
                    $doAsyncProcess($doAsyncProcess);
                } else {
                    $this->assertThat(
                        $data,
                        $this->attribute(
                            $this->objectHasAttribute('success'),
                            'progress'
                        )
                    );
            
                    if (!$data->progress->success) {
                        throw new Error($data->progress->error->message);
                    }
            
                    $this->assertThat(
                        $data,
                        $this->logicalAnd(
                            $this->objectHasAttribute('result'),
                            $this->attribute(
                                $this->objectHasAttribute('messageId'),
                                'result'
                            )
                        )
                    );
                }
            } else {
                throw $error;
            }
        };

        $doAsyncProcess($doAsyncProcess);
    }

    /**
     * Test retrieving device identification info asynchronously
     *
     * @medium
     * @return void
     */
    public function testRetrieveDeviceIdentificationInfoAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveDeviceIdentificationInfoAsync(self::$device1['id'])->then(
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('catenisNode'),
                    $this->objectHasAttribute('client'),
                    $this->objecthasAttribute('device')
                ),
                'Returned device identification info not well formed'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test listing permission events asynchronously
     *
     * @medium
     * @return void
     */
    public function testListPermissionEventsAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listPermissionEventsAsync()->then(
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
            $this->assertObjectHasAttribute('receive-msg', $data, 'Returned list of permission events not well formed');
        } else {
            throw $error;
        }
    }
    /**
     * Test setting permission for device #2 to receive messages sent from device #1 asynchronously
     *
     * @depends testListPermissionEventsAsync
     * @depends testRetrieveDeviceIdentificationInfoAsync
     * @medium
     * @return void
     */
    public function testSetPermissionToReceiveMessageAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->setPermissionRightsAsync(
            'receive-msg',
            [
                'device' => [
                    'allow' => self::$device1
                ]
            ]
        )->then(
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
            $this->assertTrue($data->success === true);
        } else {
            throw $error;
        }
    }

    /**
     * Test setting permission for device #2 to receive notification of new messages sent from device #1 asynchronously
     *
     * @depends testListPermissionEventsAsync
     * @medium
     * @return void
     */
    public function testSetPermissionToReceiveNewMessageNotificationAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->setPermissionRightsAsync(
            'receive-notify-new-msg',
            [
                'device' => [
                    'allow' => self::$device1
                ]
            ]
        )->then(
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
            $this->assertTrue($data->success === true);
        } else {
            throw $error;
        }
    }

    /**
     * Test listing notification events asynchronously
     *
     * @medium
     * @return void
     */
    public function testListNotificationEventsAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listNotificationEventsAsync()->then(
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('new-msg-received'),
                    $this->objectHasAttribute('final-msg-progress')
                ),
                'Returned list of notification events not well formed'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test checking effective permission right asynchronously
     *
     * @depends testSetPermissionToReceiveMessageAsync
     * @depends testSetPermissionToReceiveNewMessageNotificationAsync
     * @return void
     */
    public function testCheckEffectivePermissionRightAsync()
    {
        $data = null;
        $error = null;
        $deviceId = self::$device1['id'];

        self::$ctnClientAsync2->checkEffectivePermissionRightAsync('receive-msg', $deviceId)->then(
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
            $this->assertTrue(
                isset($data->$deviceId) && $data->$deviceId == true,
                'receive-msg permission right not properly set'
            );
        } else {
            throw $error;
        }

        self::$ctnClientAsync2->checkEffectivePermissionRightAsync('receive-notify-new-msg', $deviceId)->then(
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
            $this->assertTrue(
                isset($data->$deviceId) && $data->$deviceId == true,
                'receive-notify-new-msg permission right not properly set'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test receiving notification of new message (sent asynchronously) received
     *
     * @depends testListNotificationEventsAsync
     * @depends testCheckEffectivePermissionRightAsync
     * @large
     * @return array Info about the sent message
     */
    public function testReceiveNewMessageNotificationAsync()
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
        
        $wsNtfyChannel->on('notify', function ($retVal) use (&$data, &$wsNtfyChannel, &$messageId) {
            if (!is_null($messageId)) {
                // Notification received. Get returned data, close notification channel, and stop event loop
                $data = $retVal;
                $wsNtfyChannel->close();
                self::$loop->stop();
            }
        });
    
        $wsNtfyChannel->open()->then(
            function () use (&$message, &$messageId, &$error) {
                // Wait for 5 secs to make sure that notification channel is functional (user authorized)
                self::$loop->addTimer(5.0, function () use (&$message, &$messageId, &$error) {
                    // WebSocket notification channel is open.
                    //  Send message from device #1 to device #2
                    $message = 'Test message #' . rand();

                    self::$ctnClientAsync1->sendMessageAsync($message, self::$device2)->then(
                        function ($data2) use (&$messageId) {
                            // Message successfully sent. Save message ID
                            $messageId = $data2->messageId;
                        },
                        function ($ex) use (&$error) {
                            // Get error and stop event loop
                            $error = $ex;
                            self::$loop->stop();
                        }
                    );
                });
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
     * Test receiving notification of final message progress asynchronously
     *
     * @depends testListNotificationEventsAsync
     * @large
     * @return void
     */
    public function testFinalMessageProcessNotificationAsync()
    {
        $wsNtfyChannel = self::$ctnClientAsync1->createWsNotifyChannel('final-msg-progress');

        $data = null;
        $error = null;
        $message = null;
        $ephemeralMessageId = null;
        $notificationReceived = false;
        $messageLogged = false;
        
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
        
        $wsNtfyChannel->on('notify', function ($retVal) use (
            &$data,
            &$wsNtfyChannel,
            &$notificationReceived,
            &$messageLogged
        ) {
            // Notification received. Get returned data, close notification channel, and stop event loop
            $data = $retVal;
            $notificationReceived = true;

            if ($messageLogged) {
                self::$loop->stop();
            }

            $wsNtfyChannel->close();
        });

        $wsNtfyChannel->open()->then(
            function () use (&$message, &$ephemeralMessageId, &$error, &$notificationReceived, &$messageLogged) {
                // Wait for 5 secs to make sure that notification channel is functional (user authorized)
                self::$loop->addTimer(5.0, function () use (
                    &$message,
                    &$ephemeralMessageId,
                    &$error,
                    &$notificationReceived,
                    &$messageLogged
                ) {
                    // WebSocket notification channel is open.
                    //  Asynchronously log a message
                    $message = 'Test message #' . rand();

                    self::$ctnClientAsync1->logMessageAsync($message, [
                        'async' => true
                    ])->then(
                        function ($data2) use (&$ephemeralMessageId, &$notificationReceived, &$messageLogged) {
                            // Message successfully logged. Save returned provisional message ID
                            $ephemeralMessageId = $data2->provisionalMessageId;
                            $messageLogged = true;

                            if ($notificationReceived) {
                                self::$loop->stop();
                            }
                        },
                        function ($ex) use (&$error) {
                            // Get error and stop event loop
                            $error = $ex;
                            self::$loop->stop();
                        }
                    );
                });
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('ephemeralMessageId'),
                    $this->objectHasAttribute('action'),
                    $this->objectHasAttribute('progress'),
                    $this->attribute(
                        $this->logicalAnd(
                            $this->objectHasAttribute('done'),
                            $this->objectHasAttribute('success')
                        ),
                        'progress'
                    )
                )
            );

            if (!$data->progress->success) {
                throw new Error($data->progress->error->message);
            }

            $this->assertEquals($data->ephemeralMessageId, $ephemeralMessageId);

            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('result'),
                    $this->attribute(
                        $this->objectHasAttribute('messageId'),
                        'result'
                    )
                )
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test reading a message that had been sent asynchronously
     *
     * @depends testReceiveNewMessageNotificationAsync
     * @large
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadSentMessageAsync(array $messageInfo)
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
     * Test listing received messages asynchronously
     *
     * @depends testReceiveNewMessageNotificationAsync
     * @large
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testListReceivedMessagesAsync(array $messageInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->listMessagesAsync([
            'action' => 'send',
            'direction' => 'inbound',
            'fromDevices' => [
                self::$device1
            ],
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
            $this->assertTrue($data->msgCount > 0, 'No received message could be found');

            // Look for the expected message
            $messageFound = false;

            foreach ($data->messages as $message) {
                if ($message->messageId == $messageInfo['messageId']) {
                    $messageFound = true;
                    break;
                }
            }

            $this->assertTrue($messageFound, 'Unable to find received message');
        } else {
            throw $error;
        }
    }

    /**
     * Test issuing new asset asynchronously
     *
     * @medium
     * @return array Info about the issued asset
     */
    public function testIssueAssetAsync()
    {
        $data = null;
        $error = null;
        $assetName = 'Test asset #' . rand();

        self::$ctnClientAsync1->issueAssetAsync([
            'name' => $assetName,
            'description' => 'Asset used for testing purpose',
            'canReissue' => true,
            'decimalPlaces' => 2
        ], 100.00)->then(
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
            $this->assertTrue(isset($data->assetId));

            return [
                'assetName' => $assetName,
                'assetId' => $data->assetId
            ];
        } else {
            throw $error;
        }
    }

    /**
     * Test reissuing additional quantity of an asset asynchronously
     *
     * @depends testIssueAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testReissueAssetAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->reissueAssetAsync($assetInfo['assetId'], 100.00)->then(
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
            $this->assertEquals(200.00, $data->totalExistentBalance, 'Unexpected reported total issued asset amount');
        } else {
            throw $error;
        }
    }

    /**
     * Test transferring an amount of asset asynchronously
     *
     * @depends testIssueAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testTransferAssetAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->transferAssetAsync($assetInfo['assetId'], 50.00, self::$device2)->then(
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
            $this->assertEquals(150.00, $data->remainingBalance, 'Unexpected reported remaining asset amount');
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving asset info asynchronously
     *
     * @depends testIssueAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testRetrieveAssetInfoAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveAssetInfoAsync($assetInfo['assetId'])->then(
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->attributeEqualTo('name', $assetInfo['assetName']),
                    $this->attributeEqualTo('description', 'Asset used for testing purpose'),
                    $this->attributeEqualTo('canReissue', true),
                    $this->attributeEqualTo('decimalPlaces', 2),
                    $this->attribute(
                        $this->attributeEqualTo('deviceId', self::$device1['id']),
                        'issuer'
                    ),
                    $this->attributeEqualTo('totalExistentBalance', 200.00)
                ),
                'Unexpected returned asset info'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test getting asset balance asynchronously
     *
     * @depends testIssueAssetAsync
     * @depends testTransferAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testGetAssetBalanceAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->getAssetBalanceAsync($assetInfo['assetId'])->then(
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
            $this->assertEquals(150, $data->total, 'Unexpected reported asset balance');
        } else {
            throw $error;
        }
    }

    /**
     * Test listing owned assets asynchronously
     *
     * @depends testIssueAssetAsync
     * @depends testTransferAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testListOwnedAssetsAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listOwnedAssetsAsync()->then(
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
            $this->assertTrue(count($data->ownedAssets) > 0, 'Invalid number of owned assets');

            $testAsset = null;

            foreach ($data->ownedAssets as $ownedAsset) {
                if ($ownedAsset->assetId == $assetInfo['assetId']) {
                    $testAsset = $ownedAsset;
                    break;
                }
            }

            $this->assertFalse(is_null($testAsset), 'Test asset not listed as one of owned assets');

            $this->assertThat(
                $testAsset,
                $this->attribute(
                    $this->attributeEqualTo('total', 150),
                    'balance'
                ),
                'Unexpected balance of owned asset'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test listing issued assets asynchronously
     *
     * @depends testIssueAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testListIssuedAssetsAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listIssuedAssetsAsync()->then(
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
            $this->assertTrue(count($data->issuedAssets) > 0, 'Invalid number of issued assets');

            $testAsset = null;

            foreach ($data->issuedAssets as $issuedAsset) {
                if ($issuedAsset->assetId == $assetInfo['assetId']) {
                    $testAsset = $issuedAsset;
                    break;
                }
            }

            $this->assertFalse(is_null($testAsset), 'Test asset not listed as one of issued assets');

            $this->assertThat(
                $testAsset,
                $this->attributeEqualTo('totalExistentBalance', 200),
                'Unexpected balance of issued asset'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving asset issuing history asynchronously
     *
     * @depends testIssueAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testRetrieveAssetIssuingHistoryAsync(array $assetInfo)
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
            $this->assertTrue(count($data->issuanceEvents) == 2, 'Unexpected number of asset issuance events');

            foreach ($data->issuanceEvents as $issuanceEvent) {
                $this->assertThat(
                    $issuanceEvent,
                    $this->logicalAnd(
                        $this->objectHasAttribute('amount'),
                        $this->objectHasAttribute('holdingDevice'),
                        $this->objecthasAttribute('date')
                    ),
                    'Asset issuance entry not well formed'
                );
            }
        } else {
            throw $error;
        }
    }

    /**
     * Test listing asset holders asynchronously
     *
     * @depends testIssueAssetAsync
     * @depends testTransferAssetAsync
     * @medium
     * @param array $assetInfo Info about the issued asset
     * @return void
     */
    public function testListAssetHoldersAsync(array $assetInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->listAssetHoldersAsync($assetInfo['assetId'])->then(
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
            $this->assertTrue(count($data->assetHolders) == 2, 'Unexpected number of asset holders');

            foreach ($data->assetHolders as $assetHolder) {
                $this->assertThat(
                    $assetHolder,
                    $this->logicalAnd(
                        $this->objectHasAttribute('holder'),
                        $this->objecthasAttribute('balance')
                    ),
                    'Asset holder entry not well formed'
                );

                switch ($assetHolder->holder->deviceId) {
                    case self::$device1['id']:
                        $this->assertTrue(
                            $assetHolder->balance->total == 150,
                            'Unexpected asset balance for device #1'
                        );
                        break;

                    case self::$device2['id']:
                        $this->assertTrue($assetHolder->balance->total == 50, 'Unexpected asset balance for device #2');
                        break;

                    default:
                        $this->assertTrue(false, 'Unexpected asset holder');
                }
            }
        } else {
            throw $error;
        }
    }
}
