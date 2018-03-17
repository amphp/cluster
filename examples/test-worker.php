<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;
use Amp\Loop;
use function Amp\asyncCall;

Loop::run(function () {
    /** @var \Amp\Socket\Server $server */
    $server = yield Cluster::listen("tcp://0.0.0.0:1337");

    Cluster::send(\sprintf("Listening on %s in PID %s", $server->getAddress(), \getmypid()));

    asyncCall(function () use ($server) {
        /** @var \Amp\Socket\ClientSocket $client */
        while ($client = yield $server->accept()) {
            $client->write(\sprintf("Hello from PID %s!\n", \getmypid()));
            $client->close();
        }
    });

    Cluster::onTerminate(function () use ($server) {
        $server->close();
    });
});
