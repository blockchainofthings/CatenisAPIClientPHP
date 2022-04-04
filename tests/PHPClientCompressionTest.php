<?php
/**
 * Created by claudio on 2019-07-29
 */

namespace Catenis\Tests;

use PHPUnit\Framework\TestCase;
use Catenis\ApiClient;

if (empty($GLOBALS['__testEnv'])) {
    // Load default (development) test environment
    include_once __DIR__ . '/inc/DevTestEnv.php';
}

/**
 * Test cases for compression options of Catenis API Client for PHP
 */
class PHPClientCompressionTest extends TestCase
{
    protected static $testEnv;
    protected static $device1;
    protected static $accessKey1;
    protected static $ctnClient1;
    protected static $ctnClientCompr1;

    public static function setUpBeforeClass(): void
    {
        self::$testEnv = $GLOBALS['__testEnv'];
        self::$device1 = [
            'id' => self::$testEnv->device1->id
        ];
        self::$accessKey1 = self::$testEnv->device1->accessKey;

        echo "\nPHPClientCompressTest test class\n";

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

        // Instantiate (synchronous) Catenis API clients with NO compression
        self::$ctnClient1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => self::$testEnv->host,
            'environment' => self::$testEnv->environment,
            'secure' => self::$testEnv->secure,
            'useCompression' => false
        ]);

        // Instantiate (synchronous) Catenis API clients (with compression)
        self::$ctnClientCompr1 = new ApiClient(self::$device1['id'], self::$accessKey1, [
            'host' => self::$testEnv->host,
            'environment' => self::$testEnv->environment,
            'secure' => self::$testEnv->secure
        ]);
    }

    /**
     * Test logging a message to the blockchain with no compression
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogMessage()
    {
        $message = 'Test message #' . rand();

        $data = self::$ctnClient1->logMessage($message);

        $this->assertTrue(isset($data->messageId));

        return [
            'message' => $message,
            'messageId' => $data->messageId,
        ];
    }

    /**
     * Test reading the message that had been logged with no compression
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
     * Test logging a short message (below compression threshold) to the blockchain with compression
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogShortMessage()
    {
        $message = 'Short test message #' . rand();

        $data = self::$ctnClientCompr1->logMessage($message);

        $this->assertTrue(isset($data->messageId));

        return [
            'message' => $message,
            'messageId' => $data->messageId,
        ];
    }

    /**
     * Test reading the short message that had been logged with compression
     *
     * @depends testLogShortMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedShortMessage(array $messageInfo)
    {
        $data = self::$ctnClientCompr1->readMessage($messageInfo['messageId']);

        $this->assertEquals($messageInfo['message'], $data->msgData);
    }

    /**
     * Test logging a longer message (above compression threshold) to the blockchain with compression
     *
     * @medium
     * @return array Info about the logged message
     */
    public function testLogLongerMessage()
    {
        $message = 'Longer test message (#' . rand() . '): Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris tincidunt leo vitae posuere blandit. Duis pellentesque sem ac tempus volutpat. Proin pellentesque, mauris a mollis iaculis, velit nisl efficitur augue, a vestibulum leo est nec lectus. Duis aliquam dignissim lorem non tincidunt. In hac habitasse platea dictumst. Integer eget leo lorem. Sed mattis fringilla condimentum. In hac habitasse platea dictumst. In vitae hendrerit tellus. Ut cursus libero in mauris gravida elementum. Praesent finibus urna sapien, quis ornare lacus tincidunt vel. In hac habitasse platea dictumst. Integer aliquet ligula vitae sem rhoncus pellentesque. Donec non tempor lacus. Morbi bibendum bibendum risus, eget consectetur eros bibendum quis. Vestibulum lacinia ultrices libero et molestie. Quisque sit amet tristique justo, nec maximus odio. Nunc id dui vel orci cursus luctus quis eu enim. Fusce tortor nibh, dignissim sit amet pretium tincidunt, accumsan a turpis. Nam imperdiet congue dictum cras amet.';

        $data = self::$ctnClientCompr1->logMessage($message);

        $this->assertTrue(isset($data->messageId));

        return [
            'message' => $message,
            'messageId' => $data->messageId,
        ];
    }

    /**
     * Test reading the longer message that had been logged with compression
     *
     * @depends testLogLongerMessage
     * @medium
     * @param array $messageInfo Info about the logged message
     * @return void
     */
    public function testReadLoggedLongerMessage(array $messageInfo)
    {
        $data = self::$ctnClientCompr1->readMessage($messageInfo['messageId']);

        $this->assertEquals($messageInfo['message'], $data->msgData);
    }
}
