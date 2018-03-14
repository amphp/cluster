<?php

use Amp\Iterator;
use Amp\Cluster\Listener;
use Psr\Log\LoggerInterface as PsrLogger;

return function (Listener $listener, PsrLogger $logger, Iterator $iterator) {
    /** @var \Amp\Socket\Server $server */
    $server = yield $listener->listen("tcp://0.0.0.0:8080");

    $logger->info("Listening on " . $server->getAddress());

    \Amp\asyncCall(function () use ($server) {
        /** @var \Amp\Socket\ClientSocket $client */
        while ($client = yield $server->accept()) {
            $client->write(\sprintf("Hello from PID %s!\n", \getmypid()));
            $client->close();
        }
    });

    return function () use ($server) {
        $server->close();
    };
};
