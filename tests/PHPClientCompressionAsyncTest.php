<?php
/**
 * Created by claudio on 2019-07-29
 */

namespace Catenis\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop;
use Catenis\ApiClient;

/**
 * Test cases for compression options of Catenis API Client for PHP with asynchronous methods
 */
class PHPClientCompressionAsyncTest extends TestCase
{
    protected static $device1 = [
        'id' => 'drc3XdxNtzoucpw9xiRp'
    ];
    protected static $accessKey1 = '544bca61a4116e15fd7bb7c3acb2eef2a1466635446e1aa3077b6a4931be51c4c620b87f1f8fdc3c729'
        . '1f9dc32eb52f3e008755d3ecdaa57052188702c3fee61';
    protected static $ctnClientAsync1;
    protected static $ctnClientComprAsync1;
    protected static $loop;

    public static function setUpBeforeClass(): void
    {
        echo "\nPHPClientCompressionAsyncTest test class\n";

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

        // Instantiate asynchronous Catenis API clients with NO compression
        self::$ctnClientAsync1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false,
            'useCompression' => false,
            'eventLoop' => self::$loop
        ]);

        // Instantiate asynchronous Catenis API clients (with compression)
        self::$ctnClientComprAsync1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => self::$loop
        ]);
    }

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
     * Test reading the short message that had been logged asynchronously
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
     * Test logging a short message (below compression threshold) to the blockchain with compression asynchronously
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogShortMessageAsync()
    {
        $message = 'Short test message #' . rand();
        $data = null;
        $error = null;

        self::$ctnClientComprAsync1->logMessageAsync($message)->then(
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
     * Test reading the short message that had been logged with compression asynchronously
     *
     * @depends testLogShortMessageAsync
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedShortMessageAsync(array $messageInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientComprAsync1->readMessageAsync($messageInfo['messageId'])->then(
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
     * Test logging a longer message (above compression threshold) to the blockchain with compression asynchronously
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogLongerMessageAsync()
    {
        $message = 'Longer test message (#' . rand() . '): Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris tincidunt leo vitae posuere blandit. Duis pellentesque sem ac tempus volutpat. Proin pellentesque, mauris a mollis iaculis, velit nisl efficitur augue, a vestibulum leo est nec lectus. Duis aliquam dignissim lorem non tincidunt. In hac habitasse platea dictumst. Integer eget leo lorem. Sed mattis fringilla condimentum. In hac habitasse platea dictumst. In vitae hendrerit tellus. Ut cursus libero in mauris gravida elementum. Praesent finibus urna sapien, quis ornare lacus tincidunt vel. In hac habitasse platea dictumst. Integer aliquet ligula vitae sem rhoncus pellentesque. Donec non tempor lacus. Morbi bibendum bibendum risus, eget consectetur eros bibendum quis. Vestibulum lacinia ultrices libero et molestie. Quisque sit amet tristique justo, nec maximus odio. Nunc id dui vel orci cursus luctus quis eu enim. Fusce tortor nibh, dignissim sit amet pretium tincidunt, accumsan a turpis. Nam imperdiet congue dictum cras amet.';
        $data = null;
        $error = null;

        self::$ctnClientComprAsync1->logMessageAsync($message)->then(
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
     * Test reading the longer message that had been logged with compression asynchronously
     *
     * @depends testLogLongerMessageAsync
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedLongerMessageAsync(array $messageInfo)
    {
        $data = null;
        $error = null;

        self::$ctnClientComprAsync1->readMessageAsync($messageInfo['messageId'])->then(
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
