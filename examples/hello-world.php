<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Delayed;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;

// Run using bin/cluster examples/hello-world.php
// Then connect using nc localhost 1337 multiple times to see the PID of the accepting process change.

Loop::run(function () {
    /** @var \Amp\Socket\Server $server */
    $server = yield Cluster::listen("127.0.0.1:1337");

    $id = Cluster::getId();

    // Creating a log handler in this way allows the script to be run in a cluster or standalone.
    if (Cluster::isWorker()) {
        $handler = Cluster::createLogHandler();
    } else {
        $handler = new StreamHandler(ByteStream\getStdout());
        $handler->setFormatter(new ConsoleFormatter);
    }

    $logger = new Logger('worker-' . $id);
    $logger->pushHandler($handler);

    $logger->info(\sprintf("Listening on %s in PID %s", $server->getAddress(), $id));

    Cluster::onTerminate(function () use ($server, $logger) {
        $logger->info("Received termination request");
        yield new Delayed(1000);

        $server->close();
    });

    /** @var \Amp\Socket\ClientSocket $client */
    while ($client = yield $server->accept()) {
        $logger->info(\sprintf("Accepted client on %s in PID %d", $server->getAddress(), $id));
        $client->end(\sprintf("Hello from worker ID %d!\n", $id));
    }
});
