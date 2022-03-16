<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Revolt\EventLoop;

// Run using bin/cluster -w 1 examples/failing-process.php
// The single cluster worker started will fail in 1 to 5 seconds and automatically restart
// until the main process is terminated.

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

$timeout = \random_int(1, 5);

$watcher = EventLoop::delay($timeout, static function (): void {
    \trigger_error("Process failed", E_USER_ERROR);
    exit(1);
});

$logger->info(\sprintf("Worker %d started, failing in %d seconds", $id, $timeout));

Cluster::awaitTermination();

$logger->info("Received termination request");

EventLoop::cancel($watcher);
