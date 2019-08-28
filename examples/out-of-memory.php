<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;

// Run using bin/cluster -w 1 examples/out-of-memory.php
// The single cluster worker started will allocate more memory every 100 ms until failing due to
// exceeding the configured limit. The cluster watcher will automatically restart the process.

Loop::run(function () {
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

    $buffer = "";
    $character = "🍺";

    $watcher = Loop::repeat(100, function () use (&$buffer, $character, $logger, $id): void {
        $allocationSize = \random_int(2 ** 16, 2 ** 20);
        $logger->info(\sprintf("Allocating %s %d times in worker ID %d", $character, $allocationSize, $id));
        $buffer .= \str_repeat($character, $allocationSize);
        $logger->info(\sprintf("Worker ID %d is now using %d bytes of memory", $id, \memory_get_usage(true)));
    });

    $logger->info(\sprintf("Worker %d started.", $id));

    Cluster::onTerminate(function () use ($logger, $watcher): void {
        $logger->info("Received termination request");
        Loop::cancel($watcher);
    });
});
