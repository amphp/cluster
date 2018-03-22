<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;
use Amp\Loop;
use Monolog\Logger;

Loop::run(function () {
    /** @var \Amp\Socket\Server $server */
    $server = yield Cluster::listen("tcp://0.0.0.0:1337");

    $pid = \getmypid();

    $logger = new Logger('test-worker');
    $logger->pushHandler(Cluster::getLogHandler());

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
