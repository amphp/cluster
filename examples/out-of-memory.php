<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;

// Run using bin/cluster -w 1 examples/out-of-memory.php
// The single cluster worker started will continuously allocate memory until failing due to
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

    Loop::repeat(100, function () use (&$buffer, $logger, $pid) {
        $allocationSize = \random_int(2 ** 20, 2 ** 23);
        $logger->info(\sprintf("Allocating %s bytes in PID %d", $allocationSize, $pid));
        $buffer .= \str_repeat("a", $allocationSize);
    });

    $logger->info(\sprintf("Process %d started.", $pid));

    Cluster::onTerminate(function () use ($logger) {
        $logger->info("Received termination request");
    });
});
