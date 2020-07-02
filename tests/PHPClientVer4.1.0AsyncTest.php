<?php
/**
 * Created by claudio on 2020-07-30
 */

namespace Catenis\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;
use Catenis\Exception\CatenisApiException;

/**
 * Test cases for version 4.1.0 of Catenis API Client for PHP asynchronous methods
 */
class PHPClientVer4d1d0AsyncTest extends TestCase
{
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '4c1749c8e86f65e0a73e5fb19f2aa9e74a716bc22d7956bf3072b4bc3fbfe2a0d138ad0d4bcfee251e'
        . '4e5f54d6e92b8fd4eb36958a7aeaeeb51e8d2fcc4552c3';
    protected static $ctnClient1;
    protected static $ctnClientAsync1;
    protected static $ctnClientAsync2;
    protected static $loop;
    protected static $messages;

    public static function setUpBeforeClass(): void
    {
        echo "\nPHPClientVer4d1d0Test test class\n";

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

        // Instantiate a regular (synchronous) Catenis API clients
        self::$ctnClient1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false
        ]);

        // Instantiate event loop
        self::$loop = EventLoop\Factory::create();

        // Instantiate a regular, asynchronous Catenis API clients
        self::$ctnClientAsync1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);

        // Instantiate an asynchronous Catenis API Client used to call only public methods
        self::$ctnClientAsync2 = new ApiClient(null, null, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);

        // Log some test messages

        // Message #1: regular (non-off-chain) message
        self::$messages[0] = [
            'contents' => 'Test message #' . rand()
        ];

        $data = self::$ctnClient1->logMessage(self::$messages[0]['contents'], ['offChain' => false]);
        
        // Save message ID
        self::$messages[0]['id'] = $data->messageId;

        // Message #2: off-chain message
        self::$messages[1] = [
            'contents' => 'Test message #' . rand()
        ];

        $data = self::$ctnClient1->logMessage(self::$messages[1]['contents']);

        // Save message ID
        self::$messages[1]['id'] = $data->messageId;
    }

    /**
     * Test the fact that it should fail if calling a private method from a public only client instance asynchronously
     *
     * @medium
     * @return void
     */
    public function testCallPrivateMethodFailureAsync()
    {
        $message = 'Test message #' . rand();
        $data = null;
        $error = null;

        self::$ctnClientAsync2->logMessageAsync($message, ['offChain' => false])->then(
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
        if (!is_null($error)) {
            $this->assertInstanceOf(CatenisApiException::class, $error);
            $this->assertEquals(
                'Error returned from Catenis API endpoint: [401] You must be logged in to do this.',
                $error->getMessage()
            );
        } else {
            throw new Exception('Should have failed calling a private method');
        }
    }

    /**
     * Test the fact that it should be able to call a public method from a regular client instance asynchronously
     *
     * @medium
     * @return void
     */
    public function testCallPublicMethodSuccessAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync1->retrieveMessageOriginAsync(self::$messages[0]['id'])->then(
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
        $this->assertTrue(isset($data));
    }

    /**
     * Test retrieving origin of regular message without proof asynchronously
     *
     * @return void
     */
    public function testRetrieveOriginRegMsgNoProofAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->retrieveMessageOriginAsync(self::$messages[0]['id'])->then(
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
                    $this->objectHasAttribute('tx'),
                    $this->logicalNot(
                        $this->logicalAnd(
                            $this->objectHasAttribute('offChainMsgEnvelope'),
                            $this->objectHasAttribute('proof')
                        )
                    )
                ),
                'Returned message origin not well formed'
            );
            $this->assertThat(
                $data->tx,
                $this->objectHasAttribute('originDevice'),
                'Returned message origin not well formed'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving origin of regular message with proof asynchronously
     *
     * @return void
     */
    public function testRetrieveOriginRegMsgProofAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->retrieveMessageOriginAsync(
            self::$messages[0]['id'],
            'This is only a test'
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('tx'),
                    $this->logicalNot(
                        $this->objectHasAttribute('offChainMsgEnvelope')
                    ),
                    $this->objectHasAttribute('proof')
                ),
                'Returned message origin not well formed'
            );
            $this->assertThat(
                $data->tx,
                $this->objectHasAttribute('originDevice'),
                'Returned message origin not well formed'
            );
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving origin of off-chain message without proof asynchronously
     *
     * @return void
     */
    public function testRetrieveOriginOffChainMsgNoProofAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->retrieveMessageOriginAsync(self::$messages[1]['id'])->then(
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
                    $this->objectHasAttribute('offChainMsgEnvelope'),
                    $this->logicalNot(
                        $this->objectHasAttribute('proof')
                    )
                ),
                'Returned message origin not well formed'
            );
    
            if (isset($data->tx)) {
                $this->assertThat(
                    $data->tx,
                    $this->logicalNot(
                        $this->objectHasAttribute('originDevice')
                    ),
                    'Returned message origin not well formed'
                );
            }
        } else {
            throw $error;
        }
    }

    /**
     * Test retrieving origin of off-chain message with proof asynchronously
     *
     * @return void
     */
    public function testRetrieveOriginOffChainMsgProofAsync()
    {
        $data = null;
        $error = null;

        self::$ctnClientAsync2->retrieveMessageOriginAsync(
            self::$messages[1]['id'],
            'This is only a test'
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
            $this->assertThat(
                $data,
                $this->logicalAnd(
                    $this->objectHasAttribute('offChainMsgEnvelope'),
                    $this->objectHasAttribute('proof')
                ),
                'Returned message origin not well formed'
            );
            
            if (isset($data->tx)) {
                $this->assertThat(
                    $data->tx,
                    $this->logicalNot(
                        $this->objectHasAttribute('originDevice')
                    ),
                    'Returned message origin not well formed'
                );
            }
        } else {
            throw $error;
        }
    }
}
