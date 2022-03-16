<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Revolt\EventLoop;

// Run using bin/cluster -w 1 examples/out-of-memory.php
// The single cluster worker started will allocate more memory every 1000 ms until failing due to
// exceeding the configured limit. The cluster watcher will automatically restart the process.

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

$watcher = EventLoop::repeat(1000, static function () use (&$buffer, $character, $logger, $id): void {
    $allocationSize = \random_int(2 ** 20, 2 ** 24);
    $buffer .= \str_repeat($character, $allocationSize);
    $logger->info(\sprintf("Worker ID %d is now using %d bytes of memory", $id, \memory_get_usage(true)));
});

$logger->info(\sprintf("Worker %d started", $id));

Cluster::awaitTermination();

$logger->info("Received termination request");

EventLoop::cancel($watcher);
