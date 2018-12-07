<?php
/**
 * Created by claudio on 2018-12-05
 */

namespace Catenis\Notification;

use Exception;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Ratchet\Client as WsClient;
use Ratchet\Client\WebSocket;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Catenis\ApiClient;
use Catenis\Exception\WsNotifyChannelAlreadyOpenException;
use Catenis\Exception\OpenWsConnException;
use Catenis\Internal\ApiPackage;


class WsNotifyChannel extends ApiPackage implements EventEmitterInterface {
    use EventEmitterTrait;

    private $ctnApiClient;
    private $eventName;
    private $ws;

    /**
     * WsNotifyChannel constructor.
     * @param ApiClient $ctnApiClient
     * @param string $eventName
     */
    function __construct(ApiClient $ctnApiClient, $eventName) {
        $this->ctnApiClient = $ctnApiClient;
        $this->eventName = $eventName;
    }

    /**
     * Open WebSocket notification channel
     * @return PromiseInterface
     */
    function open() {
        return Promise\task(function () {
            if (isset($this->ws)) {
                // Notification channel already open. Throw exception
                throw new WsNotifyChannelAlreadyOpenException();
            }

            try {
                $wsNotifyReq = $this->invokeMethod($this->ctnApiClient, 'getWSNotifyRequest', $this->eventName);

                return WsClient\connect(
                    (string)$wsNotifyReq->getUri(),
                    [$this->invokeMethod($this->ctnApiClient, 'getNotifyWsSubprotocol')],
                    [],
                    $this->accessProperty($this->ctnApiClient, 'eventLoop')
                )->then(function (WebSocket $ws) use ($wsNotifyReq) {
                    // WebSocket connection successfully open. Save it
                    $this->ws = $ws;

                    // Wire up WebSocket connection event handlers
                    $ws->on('error', function ($error) {
                        // Emit error event
                        $this->emit('error', [$error]);

                        // And try to close WebSocket connection
                        if (isset($this->ws)) {
                            $this->ws->close(1100);
                        }
                    });

                    $ws->on('close', function ($code, $reason) {
                        // Emit close event
                        $this->emit('close', [$code, $reason]);

                        // Unset WebSocket connection object
                        $this->ws = null;
                    });

                    $ws->on('message', function ($message) {
                        // Emit notify event passing the parsed contents of the message
                        $this->emit('notify', [json_decode($message)]);
                    });

                    // Send authentication message
                    $authMsgData = [];
                    $timestampHeader = $this->invokeMethod($this->ctnApiClient, 'getTimestampHeader');

                    $authMsgData[strtolower($timestampHeader)] = $wsNotifyReq->getHeaderLine($timestampHeader);
                    $authMsgData['authorization'] = $wsNotifyReq->getHeaderLine('Authorization');

                    $ws->send(json_encode($authMsgData));
                }, function (Exception $ex) {
                    // Error opening WebSocket connection.
                    //  Just rethrows exception for now
                    throw new OpenWsConnException(null, $ex);
                });
            }
            catch (Exception $ex) {
                // Just rethrows exception for now
                throw new OpenWsConnException(null, $ex);
            }
        });
    }

    /**
     * Close WebSocket notification channel
     */
    function close() {
        if (isset($this->ws)) {
            // Close the WebSocket connection
            $this->ws->close(1000);
        }
    }
}