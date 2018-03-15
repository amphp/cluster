<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;

\Amp\Loop::run(function () {
    /** @var \Amp\Socket\Server $server */
    $server = yield Cluster::listen("tcp://0.0.0.0:1337");

    //$logger->info("Listening on " . $server->getAddress());

    \Amp\asyncCall(function () use ($server) {
        /** @var \Amp\Socket\ClientSocket $client */
        while ($client = yield $server->accept()) {
            $client->write(\sprintf("Hello from PID %s!\n", \getmypid()));
            $client->close();
        }
    });

    Cluster::onClose(function () use ($server) {
        $server->close();
    });
});
