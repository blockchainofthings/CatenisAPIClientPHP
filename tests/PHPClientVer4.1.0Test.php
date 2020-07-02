<?php
/**
 * Created by claudio on 2020-07-30
 */

namespace Catenis\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

/**
 * Test cases for version 4.1.0 of Catenis API Client for PHP
 */
class PHPClientVer4d1d0Test extends TestCase
{
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '4c1749c8e86f65e0a73e5fb19f2aa9e74a716bc22d7956bf3072b4bc3fbfe2a0d138ad0d4bcfee251e'
        . '4e5f54d6e92b8fd4eb36958a7aeaeeb51e8d2fcc4552c3';
    protected static $ctnClient1;
    protected static $ctnClient2;
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

        // Instantiate a (synchronous) Catenis API Client used to call only public methods
        self::$ctnClient2 = new ApiClient(null, null, [
            'host' => 'localhost:3000',
            'secure' => false
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
     * Test the fact that it should fail if calling a private method from a public only client instance
     *
     * @medium
     * @return void
     */
    public function testCallPrivateMethodFailure()
    {
        $this->expectExceptionMessage(
            'Error returned from Catenis API endpoint: [401] You must be logged in to do this.'
        );

        $message = 'Test message #' . rand();

        self::$ctnClient2->logMessage($message, [
            'offChain' => false
        ]);
    }

    /**
     * Test the fact that it should be able to call a public method from a regular client instance
     *
     * @medium
     * @return void
     */
    public function testCallPublicMethodSuccess()
    {
        $data = self::$ctnClient1->retrieveMessageOrigin(self::$messages[0]['id']);

        $this->assertTrue(isset($data));
    }

    /**
     * Test retrieving origin of regular message without proof
     *
     * @return void
     */
    public function testRetrieveOriginRegMsgNoProof()
    {
        $data = self::$ctnClient2->retrieveMessageOrigin(self::$messages[0]['id']);

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
    }

    /**
     * Test retrieving origin of regular message with proof
     *
     * @return void
     */
    public function testRetrieveOriginRegMsgProof()
    {
        $data = self::$ctnClient2->retrieveMessageOrigin(
            self::$messages[0]['id'],
            'This is only a test'
        );

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
    }

    /**
     * Test retrieving origin of off-chain message without proof
     *
     * @return void
     */
    public function testRetrieveOriginOffChainMsgNoProof()
    {
        $data = self::$ctnClient2->retrieveMessageOrigin(self::$messages[1]['id']);

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
    }

    /**
     * Test retrieving origin of off-chain message with proof
     *
     * @return void
     */
    public function testRetrieveOriginOffChainMsgProof()
    {
        $data = self::$ctnClient2->retrieveMessageOrigin(
            self::$messages[1]['id'],
            'This is only a test'
        );

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
    }
}
