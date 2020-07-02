# Catenis API PHP Client

This library is used to make it easier to access the Catenis Enterprise API services from PHP applications.

This current release (4.1.0) targets version 0.10 of the Catenis Enterprise API.

## Installation

The recommended way to install the Catenis API PHP client is using [Composer](https://getcomposer.org).

To add Catenis API Client as a dependency to your project, follow these steps:

1. Add the following repository entries to your `composer.json` file:

```json
{
  "repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/claudiosdc/react-guzzle-psr7.git"
    },
    {
        "type": "vcs",
        "url": "https://github.com/claudiosdc/react-guzzle-http-client.git"
    },
    {
        "type": "vcs",
        "url": "https://github.com/claudiosdc/reactphp-buzz.git"
    }
  ]
}
```

2. Then add the required dependencies by either:

Issuing the following command:

```shell
composer require blockchainofthings/catenis-api-client:~3.0 wyrihaximus/react-guzzle-psr7:dev-decode-content wyrihaximus/react-guzzle-http-client:dev-decode-content clue/buzz-react:dev-decode-content
```

Or editing the `composer.json` file directly:

```json
{
    "require": {
        "blockchainofthings/catenis-api-client": "~3.0",
        "wyrihaximus/react-guzzle-psr7": "dev-decode-content",
        "wyrihaximus/react-guzzle-http-client": "dev-decode-content",
        "clue/buzz-react": "dev-decode-content"
    }
}
```

> **Note**: normally, only the blockchainofthings/catenis-api-client package would need to be listed. However, since this release
 of the Catenis API library depends on special patched versions of the other three packages, they also need to be listed here.

## Usage

Just include Composer's `vendor/autoload.php` file and Catenis API Client's components will be available to be used in
 your code.

```php
require __DIR__ . 'vendor/autoload.php';
```

### Instantiate the client

```php
$ctnApiClient = new \Catenis\ApiClient(
    $deviceId,
    $apiAccessSecret,
    [
        'environment' => 'sandbox'
    ]
);
```

Optionally, the client can be instantiated without passing both the `$deviceId` and the `$apiAccessSecret` parameters or setting them
 to *null* as shown below. In this case, the resulting client object should be used to call only **public** API methods.

```php
$ctnApiClient = new \Catenis\ApiClient(null, null,
    [
        'environment' => 'sandbox'
    ]
);
```

#### Constructor options

The following options can be used when instantiating the client:

- **host** \[string\] - (optional, default: <b>*'catenis.io'*</b>) Host name (with optional port) of target Catenis API server.
- **environment** \[string\] - (optional, default: <b>*'prod'*</b>) Environment of target Catenis API server. Valid values: *'prod'*, *'sandbox'*.
- **secure** \[boolean\] - (optional, default: ***true***) Indicates whether a secure connection (HTTPS) should be used.
- **version** \[string\] - (optional, default: <b>*'0.10'*</b>) Version of Catenis API to target.
- **useCompression** \[boolean\] - (optional, default: ***true***) Indicates whether request/response body should be compressed.
- **compressThreshold** \[integer\] - (optional, default: ***1024***) Minimum size, in bytes, of request body for it to be compressed.
- **timeout** \[float|integer\] - (optional, default: ***0, no timeout***) Timeout, in seconds, to wait for a response.
- **eventLoop** \[React\EventLoop\LoopInterface\] - (optional) Event loop to be used for asynchronous API method calling mechanism.
- **pumpTaskQueue** \[boolean] - (optional, default: ***true***) Indicates whether to force the promise task queue to be periodically run.
- **pumpInterval** \[integer] - (optional, default: ***10***) Time, in milliseconds, specifying the interval for periodically running the task queue.

### Asynchronous method calls

Each API method has an asynchronous counterpart method that has an *Async* suffix, e.g. `logMessageAsync`.

The asynchronous methods return a promise, and, when used with an event loop, can have their result processed in a
 asynchronous way.

To be used with an event loop, pass the event loop instance as an option when instantiating the *ApiClient* object.

```php
$loop = \React\EventLoop\Factory::create();

$ctnApiClient = new \Catenis\ApiClient(
    $deviceId,
    $apiAccessSecret,
    [
        'environment' => 'sandbox'
        'eventLoop' => $loop
    ]
);
```

Example of processing asynchronous API method calls.

```php
$ctnApiClient->logMessageAsync('My message')->then(
    function (stdClass $data) {
        // Process returned data
    },
    function (\Catenis\Exception\CatenisException $ex) {
        // Process exception
    }
);
```

> **Note**: for promises to be asynchronously resolved, the promise task queue should be periodically run (i.e.
 `\GuzzleHttp\Promise\queue()->run()`). By default, the Catenis API PHP client will run the promise task queue
 every 10 milliseconds. To set a different interval for running the promise task queue, pass the optional
 parameter ***pumpInterval*** with an integer value corresponding to the desired time in milliseconds when
 instantiating the Catenis API PHP client (e.g. `'pumpInterval' => 5`). Alternatively, to avoid that the promise
 task queue be run altogether, pass the optional parameter ***pumpTaskQueue*** set to *false* when instantiating the
 client (i.e. `'pumpTaskQueue' => false`). In that case, the end user shall be responsible to run the promise
 task queue.

To force the returned promise to complete and get the data returned by the API method, use its `wait()` method.

```php
try {
    $data = $ctnApiClient->logMessageAsync('My message')->wait();

    // Process returned data
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Returned data

On successful calls to the Catenis API, the data returned by the client library methods **only** include the `data`
 property of the JSON originally returned in response to a Catenis API request.

For example, you should expect the following data to be returned from a successful call to the `logMessage` method:

```shell
object(stdClass)#54 (1) {
  ["messageId"]=>
  string(20) "m57enyYQK7QmqSxgP94j"
}
```

### Logging (storing) a message to the blockchain

#### Passing the whole message's contents at once

```php
try {
    $data = $ctnApiClient->logMessage('My message', [
        'encoding' => 'utf8',
        'encrypt' => true,
        'offChain' => true,
        'storage' => 'auto'
    ]);

    // Process returned data
    echo 'ID of logged message: ' . $data->messageId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

#### Passing the message's contents in chunks

```php
$message = [
    'First part of message',
    'Second part of message',
    'Third and last part of message'
];

try {
    $continuationToken = null;

    foreach ($message as $chunk) {
        $data = $ctnApiClient->logMessage([
            'data' => $chunk,
            'isFinal' => false,
            'continuationToken' => $continuationToken
        ], [
            'encoding' => 'utf8'
        ]);

        $continuationToken = $data->continuationToken;
    }

    // Signal that message has ended and get result
    $data = $ctnApiClient->logMessage([
        'isFinal' => true,
        'continuationToken' => $continuationToken
    ], [
        'encrypt' => true,
        'offChain' => true,
        'storage' => 'auto'
    ]);

    echo 'ID of logged message: ' . $data->messageId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

#### Logging message asynchronously

```php
try {
    $data = $ctnApiClient->logMessage('My message', [
        'encoding' => 'utf8',
        'encrypt' => true,
        'offChain' => true,
        'storage' => 'auto',
        'async' => true
    ]);

    // Start pooling for asynchronous processing progress
    $provisionalMessageId = $data->provisionalMessageId;
    $result = null;
    wait(1);

    do {
        $data = $ctnApiClient->retrieveMessageProgress($provisionalMessageId);

        // Process returned data
        echo 'Number of bytes processed so far: ' . $data->progress->bytesProcessed . PHP_EOL;
            
        if ($data->progress->done) {
            if ($data->progress->success) {
                // Get result
                $result = $data->result;
            } else {
                // Process error
                echo 'Asynchronous processing error: [' . $data->progress->error->code . '] - '
                    . $data->progress->error->message . PHP_EOL;
            }
        } else {
            // Asynchronous processing not done yet. Wait before continuing pooling
            wait(3);
        }
    } while (is_null($result));

    echo 'ID of logged message: ' . $result->messageId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Sending a message to another device

#### Passing the whole message's contents at once

```php
try {
    $data = $ctnApiClient->sendMessage('My message', [
        'id' => $targetDeviceId,
        'isProdUniqueId' => false
    ], [
        'encoding' => 'utf8',
        'encrypt' => true,
        'offChain' => true,
        'storage' => 'auto',
        'readConfirmation' => true
    ]);

    // Process returned data
    echo 'ID of sent message: ' . $data->messageId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

#### Passing the message's contents in chunks

```php
$message = [
    'First part of message',
    'Second part of message',
    'Third and last part of message'
];

try {
    $continuationToken = null;

    foreach ($message as $chunk) {
        $data = $ctnApiClient->sendMessage([
            'data' => $chunk,
            'isFinal' => false,
            'continuationToken' => $continuationToken
        ], [
            'id' => $targetDeviceId,
            'isProdUniqueId' => false
        ], [
            'encoding' => 'utf8'
        ]);

        $continuationToken = $data->continuationToken;
    }

    // Signal that message has ended and get result
    $data = $ctnApiClient->sendMessage([
        'isFinal' => true,
        'continuationToken' => $continuationToken
    ], [
        'id' => $targetDeviceId,
        'isProdUniqueId' => false
    ], [
        'encrypt' => true,
        'offChain' => true,
        'storage' => 'auto',
        'readConfirmation' => true
    ]);

    echo 'ID of sent message: ' . $data->messageId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

#### Sending message asynchronously

```php
try {
    $data = $ctnApiClient->sendMessage('My message', [
        'id' => $targetDeviceId,
        'isProdUniqueId' => false
    ], [
        'encoding' => 'utf8',
        'encrypt' => true,
        'offChain' => true,
        'storage' => 'auto',
        'readConfirmation' => true,
        'async' => true
    ]);

    // Start pooling for asynchronous processing progress
    $provisionalMessageId = $data->provisionalMessageId;
    $result = null;
    wait(1);

    do {
        $data = $ctnApiClient->retrieveMessageProgress($provisionalMessageId);

        // Process returned data
        echo 'Number of bytes processed so far: ' . $data->progress->bytesProcessed . PHP_EOL;
            
        if ($data->progress->done) {
            if ($data->progress->success) {
                // Get result
                $result = $data->result;
            } else {
                // Process error
                echo 'Asynchronous processing error: [' . $data->progress->error->code . '] - '
                    . $data->progress->error->message . PHP_EOL;
            }
        } else {
            // Asynchronous processing not done yet. Wait before continuing pooling
            wait(3);
        }
    } while (is_null($result));

    echo 'ID of sent message: ' . $result->messageId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Reading a message

#### Retrieving the whole read message's contents at once
 
```php
try {
    $data = $ctnApiClient->readMessage($messageId, 'utf8');

    // Process returned data
    if ($data->msgInfo->action === 'send') {
        echo 'Message sent from: ' . print_r($data->msgInfo->from, true);
    }

    echo 'Read message: ' . $data->msgData . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

#### Retrieving the read message's contents in chunks

```php
try {
    $continuationToken = null;
    $chunkCount = 1;

    do {
        $data = $ctnApiClient->readMessage($messageId, [
            'encoding' => 'utf8',
            'continuationToken' => $continuationToken,
            'dataChunkSize' => 1024
        ]);

        // Process returned data
        if (isset($data->msgInfo) && $data->msgInfo->action == 'send') {
            echo 'Message sent from: ' . $data->msgInfo->from);
        }
        
        echo 'Read message (chunk ' . $chunkCount . '): ' . $data->msgData);
        
        if (isset($data->continuationToken)) {
            // Get continuation token to continue reading message
            $continuationToken = $data->continuationToken;
            $chunkCount += 1;
        } else {
            $continuationToken = null;
        }
    } while (!is_null($continuationToken));
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

#### Reading message asynchronously

```php
try {
    // Request to read message asynchronously
    $data = $ctnApiClient->readMessage($messageId, [
        'async' => true
    ]);

    // Start pooling for asynchronous processing progress
    $cachedMessageId = $data->cachedMessageId;
    $result = null;
    wait(1);

    do {
        $data = $ctnApiClient->retrieveMessageProgress($cachedMessageId);

        // Process returned data
        echo 'Number of bytes processed so far: ' . $data->progress->bytesProcessed . PHP_EOL;
            
        if ($data->progress->done) {
            if ($data->progress->success) {
                // Get result
                $result = $data->result;
            } else {
                // Process error
                echo 'Asynchronous processing error: [' . $data->progress->error->code . '] - '
                    . $data->progress->error->message . PHP_EOL;
            }
        } else {
            // Asynchronous processing not done yet. Wait before continuing pooling
            wait(3);
        }
    } while (is_null($result));

    // Retrieve read message
    $data = $ctnApiClient->readMessage($messageId, [
        'encoding' => 'utf8',
        'continuationToken' => $result->continuationToken
    ]);

    if ($data->msgInfo->action === 'send') {
        echo 'Message sent from: ' . print_r($data->msgInfo->from, true);
    }

    echo 'Read message: ' . $data->msgData . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving information about a message's container

```php
try {
    $data = $ctnApiClient->retrieveMessageContainer($messageId);

    // Process returned data
    if (isset($data->offChain)) {
        echo 'IPFS CID of Catenis off-chain message envelope: ' . $data->offChain->cid . PHP_EOL;
    }
    
    if (isset($data->blockchain)) {
        echo 'ID of blockchain transaction containing the message: ' . $data->blockchain->txid . PHP_EOL;
    }

    if (isset($data->externalStorage)) {
        echo 'IPFS reference to message: ' . $data->externalStorage->ipfs . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving information about a message's origin

```php
try {
    $data = $ctnApiClient->retrieveMessageOrigin($messageId, 'Any text to be signed');

    // Process returned data
    if (isset($data->tx)) {
        echo 'Catenis message transaction info: ' . print_r($data->tx, true);
    }
    
    if (isset($data->offChainMsgEnvelope)) {
        echo 'Off-chain message envelope info: ' . print_r($data->offChainMsgEnvelope, true);
    }

    if (isset($data->proof)) {
        echo 'Origin proof info: ' . print_r($data->proof, true);
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving asynchronous message processing progress

```php
try {
    $data = $ctnApiClient->retrieveMessageProgress($provisionalMessageId);

    // Process returned data
    echo 'Number of bytes processed so far: ' . $data->progress->bytesProcessed . PHP_EOL;

    if ($data->progress->done) {
        if ($data->progress->success) {
            // Get result
            echo 'Asynchronous processing result: ' . $data->result . PHP_EOL;
        }
        else {
            // Process error
            echo 'Asynchronous processing error: [' . $data->progress->error->code . '] - '
                . $data->progress->error->message . PHP_EOL;
        }
    } else {
        // Asynchronous processing not done yet. Continue pooling
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

> **Note**: see the *Logging message asynchronously*, *Sending message asynchronously* and *Reading message
>asynchronously* sections above for more complete examples.

### Listing messages

```php
try {
    $data = $ctnApiClient->listMessages([
        'action' => 'send',
        'direction' => 'inbound',
        'readState' => 'unread',
        'startDate' => new \DateTime('20170101T000000Z')
    ], 200, 0);

    // Process returned data
    if ($data->msgCount > 0) {
        echo 'Returned messages: ' . print_r($data->messages, true);
        
        if ($data->hasMore) {
            echo 'Not all messages have been returned' . PHP_EOL;
        }
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

> **Note**: the parameters taken by the *listMessages* method do not exactly match the parameters taken by the List
 Messages Catenis API method. Most of the parameters, with the exception of the last two (`limit` and `skip`), are
 mapped to keys of the first parameter (`selector`) of the *listMessages* method with a few singularities: parameters
 `fromDeviceIds` and `fromDeviceProdUniqueIds` and parameters `toDeviceIds` and `toDeviceProdUniqueIds` are replaced with
 keys `fromDevices` and `toDevices`, respectively. Those keys accept for value an indexed array of device ID associative arrays,
 which is the same type of associative array taken by the first parameter (`targetDevice`) of the *sendMessage* method.
 Also, the date keys, `startDate` and `endDate`, accept not only strings containing ISO8601 formatted dates/times for values
 but also *Date* objects.

### Issuing an amount of a new asset

```php
try {
    $data = $ctnApiClient->issueAsset([
        'name' => 'XYZ001',
        'description' => 'My first test asset',
        'canReissue' => true,
        'decimalPlaces' => 2
    ], 1500.00, null);

    // Process returned data
    echo 'ID of newly issued asset: ' . $data->assetId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Issuing an additional amount of an existing asset

```php
try {
    $data = $ctnApiClient->reissueAsset($assetId, 650.25, [
        'id' => $otherDeviceId,
        'isProdUniqueId' => false
    ]);

    // Process returned data
    echo 'Total existent asset balance (after issuance): ' . $data->totalExistentBalance . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Transferring an amount of an asset to another device

```php
try {
    $data = $ctnApiClient->transferAsset($assetId, 50.75, [
        'id' => $otherDeviceId,
        'isProdUniqueId' => false
    ]);

    // Process returned data
    echo 'Remaining asset balance: ' . $data->remainingBalance . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving information about a given asset

```php
try {
    $data = $ctnApiClient->retrieveAssetInfo($assetId);

    // Process returned data
    echo 'Asset info:' . print_r($data, true);
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Getting the current balance of a given asset held by the device

```php
try {
    $data = $ctnApiClient->getAssetBalance($assetId);

    // Process returned data
    echo 'Current asset balance: ' . $data->balance->total . PHP_EOL;
    echo 'Amount not yet confirmed: ' . $data->balance->unconfirmed . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Listing assets owned by the device

```php
try {
    $data = $ctnApiClient->listOwnedAssets(200, 0);
    
    // Process returned data
    foreach ($data->ownedAssets as $idx => $ownedAsset) {
        echo 'Owned asset #' . ($idx + 1) . ':' . PHP_EOL;
        echo '  - asset ID: ' . $ownedAsset->assetId . PHP_EOL;
        echo '  - current asset balance: ' . $ownedAsset->balance->total . PHP_EOL;
        echo '  - amount not yet confirmed: ' . $ownedAsset->balance->unconfirmed . PHP_EOL;
    }

    if ($data->hasMore) {
        echo 'Not all owned assets have been returned' . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Listing assets issued by the device

```php
try {
    $data = $ctnApiClient->listIssuedAssets(200, 0);
    
    // Process returned data
    foreach ($data->issuedAssets as $idx => $issuedAsset) {
        echo 'Issued asset #' . ($idx + 1) . ':' . PHP_EOL;
        echo '  - asset ID: ' . $issuedAsset->assetId . PHP_EOL;
        echo '  - total existent balance: ' . $issuedAsset->totalExistentBalance . PHP_EOL;
    }

    if ($data->hasMore) {
        echo 'Not all issued assets have been returned' . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving issuance history for a given asset

```php
try {
    $data = $ctnApiClient->retrieveAssetIssuanceHistory($assetId, new \DateTime('20170101T000000Z'), null, 200, 0);
    
    // Process returned data
    foreach ($data->issuanceEvents as $idx => $issuanceEvent) {
        echo 'Issuance event #', ($idx + 1) . ':' . PHP_EOL;
        echo '  - issued amount: ' . $issuanceEvent->amount . PHP_EOL;
        echo '  - device to which issued amount had been assigned: ' . print_r($issuanceEvent->holdingDevice, true);
        echo '  - date of issuance: ' . $issuanceEvent->date . PHP_EOL;
    }

    if ($data->hasMore) {
        echo 'Not all asset issuance events have been returned' . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

> **Note**: the parameters of the *retrieveAssetIssuanceHistory* method are slightly different than the ones taken by
 the Retrieve Asset Issuance History Catenis API method. In particular, the date parameters, `startDate` and `endDate`,
 accept not only strings containing ISO8601 formatted dates/times but also *DateTime* objects.

### Listing devices that currently hold any amount of a given asset

```php
try {
    $data = $ctnApiClient->listAssetHolders($assetId, 200, 0);
    
    // Process returned data
    foreach ($data->assetHolders as $idx => $assetHolder) {
        echo 'Asset holder #' . ($idx + 1) . ':' . PHP_EOL;
        echo '  - device holding an amount of the asset: ' . print_r($assetHolder->holder, true);
        echo '  - amount of asset currently held by device: ' . $assetHolder->balance->total . PHP_EOL;
        echo '  - amount not yet confirmed: ' . $assetHolder->balance->unconfirmed . PHP_EOL;
    }

    if ($data->hasMore) {
        echo 'Not all asset holders have been returned' . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Listing system defined permission events

```php
try {
    $data = $ctnApiClient->listPermissionEvents();

    // Process returned data
    foreach ($data as $eventName => $description) {
        echo 'Event name: ' . $eventName . '; event description: ' . $description . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving permission rights currently set for a specified permission event

```php
try {
    $data = $ctnApiClient->retrievePermissionRights('receive-msg');
    
    // Process returned data
    echo 'Default (system) permission right: ' . $data->system . PHP_EOL;
    
    if (isset($data->catenisNode)) {
        if (isset($data->catenisNode->allow)) {
            echo 'Index of Catenis nodes with \'allow\' permission right: ' . implode($data->catenisNode->allow, ', ')
                . PHP_EOL;
        }
        
        if (isset($data->catenisNode->deny)) {
            echo 'Index of Catenis nodes with \'deny\' permission right: ' . implode($data->catenisNode->deny, ', ')
                . PHP_EOL;
        }
    }
    
    if (isset($data->client)) {
        if (isset($data->client->allow)) {
            echo 'ID of clients with \'allow\' permission right: ' . implode($data->client->allow, ', ') . PHP_EOL;
        }
        
        if (isset($data->client->deny)) {
            echo 'ID of clients with \'deny\' permission right: ' . implode($data->client->deny, ', ') . PHP_EOL;
        }
    }
    
    if (isset($data->device)) {
        if (isset($data->device->allow)) {
            echo 'Devices with \'allow\' permission right: ' . print_r($data->device->allow, true);
        }
        
        if (isset($data->device->deny)) {
            echo 'Devices with \'deny\' permission right: ' . print_r($data->device->deny, true);
        }
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Setting permission rights at different levels for a specified permission event

```php
try {
    $data = $ctnApiClient->setPermissionRights(
        'receive-msg',
        [
            'system' => 'deny',
            'catenisNode' => [
                'allow' => 'self'
            ],
            'client' => [
                'allow' => [
                    'self',
                    $clientId
                ]
            ],
            'device' => [
                'deny' => [[
                    'id' => $deviceId1
                ], [
                    'id' => 'ABCD001',
                    'isProdUniqueId' => true
                ]]
            ]
        ]
    );

    // Process returned data
    echo 'Permission rights successfully set' . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Checking effective permission right applied to a given device for a specified permission event

```php
try {
    $data = $ctnApiClient->checkEffectivePermissionRight('receive-msg', $deviceProdUniqueId, true);

    // Process returned data
    $deviceId = array_keys(get_object_vars($data))[0];
    echo 'Effective right for device ' . $deviceId . ': ' . $data->$deviceId . PHP_EOL;
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Retrieving identification information of a given device

```php
try {
    $data = $ctnApiClient->retrieveDeviceIdentificationInfo($deviceId, false);
    
    // Process returned data
    echo 'Device\'s Catenis node ID info:' . print_r($data->catenisNode, true);
    echo 'Device\'s client ID info:' . print_r($data->client, true);
    echo 'Device\'s own ID info:' . print_r($data->device, true);
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

### Listing system defined notification events

```php
try {
    $data = $ctnApiClient->listNotificationEvents();

    // Process returned data
    foreach ($data as $eventName => $description) {
        echo 'Event name: ' . $eventName . '; event description: ' . $description . PHP_EOL;
    }
} catch (\Catenis\Exception\CatenisException $ex) {
    // Process exception
}
```

## Notifications

The Catenis API PHP client makes it easy for receiving notifications from the Catenis system by embedding a
WebSocket client. All the end user needs to do is open a WebSocket notification channel for the desired Catenis
notification event, and monitor the activity on that channel.

Notifications require that an event loop be used. You should then pass the event loop instance as an option when
instantiating the *ApiClient* object, in the same way as when using the asynchronous API methods.

```php
$loop = \React\EventLoop\Factory::create();

$ctnApiClient = new \Catenis\ApiClient(
    $deviceId,
    $apiAccessSecret, [
        'environment' => 'sandbox'
        'eventLoop' => $loop
    ]
);
```

> **Note**: if no event loop instance is passed when instantiating the *ApiClient* object, an internal event loop is
 created. However, in that case, notifications will only be processed once the application is shut down (and the event
 loop is finally run).

### Receiving notifications

Instantiate WebSocket notification channel object.

```php
$wsNtfyChannel = $ctnApiClient->createWsNotifyChannel($eventName);
```

Add listeners.

```php
$wsNtfyChannel->on('error', function ($error) {
    // Process error in the underlying WebSocket connection
});

$wsNtfyChannel->on('close', function ($code, $reason) {
    // Process indication that underlying WebSocket connection has been closed
});

$wsNtfyChannel->on('open', function () {
    // Process indication that notification channel is successfully open
    //  and ready to send notifications 
});

$wsNtfyChannel->on('notify', function ($data) {
    // Process received notification
    echo 'Received notification:' . PHP_EOL;
    print_r($data);
});
```

> **Note**: the `data` argument of the *notify* event contains the deserialized JSON notification message (a *stdClass*
 instance) of the corresponding notification event.

Open notification channel.

```php
$wsNtfyChannel->open()->then(
    function () {
        // WebSocket client successfully connected. Wait for open event to make
        //  sure that notification channel is ready to send notifications
    },
    function (\Catenis\Exception\WsNotificationException $ex) {
        // Process exception
    }
);
```

> **Note**: the `open()` method of the WebSocket notification channel object works in an asynchronous way, and as such
 it returns a promise like the asynchronous API methods do.

Close notification channel.

```php
$wsNtfyChannel->close();
```

## Error handling

Error conditions are reported by means of exception objects, which are thrown, in case of synchronous methods, or passed
as an argument, in case of asynchronous methods.

### API method exceptions

The following exceptions can take place when calling API methods:

- **CatenisClientException** - Indicates that an error took place while trying to call the Catenis API endpoint.
- **CatenisApiException** - Indicates that an error was returned by the Catenis API endpoint.

> **Note**: these two exceptions derive from a single exception, namely **CatenisException**.

The CatenisApiException object provides custom methods that can be used to retrieve some specific data about the
error condition, as follows:

- `getHttpStatusCode()` - Returns the numeric status code of the HTTP response received from the Catenis API endpoint.

- `getHttpStatusMessage()` - Returns the text associated with the status code of the HTTP response received from the
 Catenis API endpoint.
 
- `getCatenisErrorMessage()` - Returns the Catenis error message returned from the Catenis API endpoint.

Usage example:

```php
try {
    $data = $ctnApiClient->readMessage('INVALID_MSG_ID', null);
    
    // Process returned data
} catch (\Catenis\Exception\CatenisException $ex) {
    if ($ex instanceof \Catenis\Exception\CatenisApiException) {
        // Catenis API error
        echo 'HTTP status code: ' . $ex->getHttpStatusCode() . PHP_EOL;
        echo 'HTTP status message: ' . $ex->getHttpStatusMessage() . PHP_EOL;
        echo 'Catenis error message: ' . $ex->getCatenisErrorMessage() . PHP_EOL;
        echo 'Compiled error message: ' . $ex->getMessage() . PHP_EOL;
    } else {
        // Client error
        echo $ex . PHP_EOL;
    }
}
```

Expected result:

```
HTTP status code: 400
HTTP status message: Bad Request
Catenis error message: Invalid message ID
Compiled error message: Error returned from Catenis API endpoint: [400] Invalid message ID
```

## WebSocket notification exceptions

The following exceptions can take place when opening a WebSocket notification channel:

- **OpenWsConnException** - Indicates that an error took place while establishing the underlying WebSocket connection.
- **WsNotifyChannelAlreadyOpenException** - Indicates that the WebSocket notification channel (for that device and
 notification event) is already open.

> **Note**: these two exceptions derive from a single exception, namely **WsNotificationException**, which
 in turn also derives from **CatenisException**.

Usage example:

```php
$wsNtfyChannel->open()->then(
    function () {
        // WebSocket client successfully connected. Wait for open event to make
        //  sure that notification channel is ready to send notifications
    },
    function (\Catenis\Exception\WsNotificationException $ex) {
        if ($ex instanceof \Catenis\Exception\OpenWsConnException) {
            // Error opening WebSocket connection
            echo $ex . PHP_EOL;
        } else {
            // WebSocket nofitication channel already open
        }
    }
);
```

## Catenis Enterprise API Documentation

For further information on the Catenis Enterprise API, please reference the [Catenis Enterprise API Documentation](https://catenis.com/docs/api).

## License

This Node.js module is released under the [MIT License](LICENSE). Feel free to fork, and modify!

Copyright © 2020, Blockchain of Things Inc.
