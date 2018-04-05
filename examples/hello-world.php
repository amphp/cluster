<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;
use Amp\Loop;
use Monolog\Logger;
use function Amp\Cluster\createLogHandler;

// Run using bin/cluster -s examples/hello-world.php
// Then connect using nc localhost 1337 multiple times to see the PID of the accepting process change.

Loop::run(function () {
    /** @var \Amp\Socket\Server $server */
    $server = yield Cluster::listen("127.0.0.1:1337");

    $pid = \getmypid();

    $logger = new Logger('worker-' . $pid);
    $logger->pushHandler(createLogHandler());

    $logger->info(\sprintf("Listening on %s in PID %s", $server->getAddress(), $pid));

    Cluster::onTerminate(function () use ($server) {
        $server->close();
    });

    /** @var \Amp\Socket\ClientSocket $client */
    while ($client = yield $server->accept()) {
        $logger->info(\sprintf("Accepted client on %s in PID %d", $server->getAddress(), $pid));
        $client->end(\sprintf("Hello from PID %d!\n", $pid));
    }
});
