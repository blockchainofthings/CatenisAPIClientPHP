<?php
/**
 * Created by claudio on 2018-11-21
 */

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop;
use Catenis\ApiClient;

try {
    // Setup for calling Catenis API endpoints asynchronously
    $loop = EventLoop\Factory::create();

    // The lines below are required only if 'pumTaskQueue' option is set to false
    //$queue = \GuzzleHttp\Promise\queue();
    //$loop->addPeriodicTimer(0, [$queue, 'run']);

    // Instantiate Catenis API client passing event loop
    $ctnClient = new ApiClient('d8YpQ7jgPBJEkBrnvp58',
        '61281120a92dc6267af11170d161f64478b0a852f3cce4286b8a1b82afd2de7077472b6f7b93b6d554295d859815a37cb89f4f875b7aaeb0bd2babd9531c6883', [
            'host' => 'localhost:3000',
            'secure' => false,
            'eventLoop' => $loop,
            //'pumpTaskQueue' => false
        ]
    );

    /*$result = //$client->logMessage('This is only a test (from PHP client)'); // mPb3drcWvonLA6dY6yjr
        $client->readMessage('mPb3drcWvonLA6dY6yjr');*/

    /*$result = $client->listMessages(['action' => 'log']);
    var_dump($result);*/

    // Asynchronous call
    $ctnClient->readMessageAsync('mPb3drcWvonLA6dY6yjr')->then(
        function ($result) {
            echo "Read Message (asynchronous) result:\n";
            var_dump($result);
        },
        function ($ex) {
            echo $ex;
        }
    );

    // Synchronous call
    /*try {
        $result = $ctnClient->readMessage('mPb3drcWvonLA6dY6yjr');

        echo "Read message result:\n";
        var_dump($result);
    }
    catch (Exception $ex) {
        echo $ex;
    }*/

    //$loop->addPeriodicTimer(1, function () {});

    $loop->run();
}
catch (Exception $ex) {
    echo $ex;
}
