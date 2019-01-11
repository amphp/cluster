<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;

// Run using bin/cluster -w 1 examples/failing-process.php
// The single cluster worker started will fail in 1 to 5 seconds and automatically restart
// until the main process is terminated.

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

    $timeout = \random_int(1, 5);

    $watcher = Loop::delay($timeout * 1000, function () {
        \trigger_error("Process failed", E_USER_ERROR);
        exit(1);
    });

    $logger->info(\sprintf("Process %d started, failing in %d seconds", $pid, $timeout));

    Cluster::onTerminate(function () use ($logger, $watcher) {
        $logger->info("Received termination request");
        Loop::cancel($watcher);
    });
});
