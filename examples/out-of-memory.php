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
    $pid = \getmypid();

    // Creating a log handler in this way allows the script to be run in a cluster or standalone.
    if (Cluster::isWorker()) {
        $handler = Cluster::createLogHandler();
    } else {
        $handler = new StreamHandler(ByteStream\getStdout());
        $handler->setFormatter(new ConsoleFormatter);
    }

    $logger = new Logger('worker-' . $pid);
    $logger->pushHandler($handler);

    $buffer = "";
    $character = "🍺";

    $watcher = Loop::repeat(100, function () use (&$buffer, $character, $logger, $pid) {
        $allocationSize = \random_int(2 ** 16, 2 ** 20);
        $logger->info(\sprintf("Allocating %s %d times in PID %d", $character, $allocationSize, $pid));
        $buffer .= \str_repeat($character, $allocationSize);
        $logger->info(\sprintf("PID %d is now using %d bytes of memory", $pid, \memory_get_usage(true)));
    });

    $logger->info(\sprintf("Process %d started.", $pid));

    Cluster::onTerminate(function () use ($logger, $watcher) {
        $logger->info("Received termination request");
        Loop::cancel($watcher);
    });
});
