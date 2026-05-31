<?php declare(strict_types=1);

require dirname(__DIR__, 2) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Cluster\ClusterLogSerializationProcessor;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Revolt\EventLoop;

// Run using bin/cluster -w 1 examples/cluster/out-of-memory.php
// The single cluster worker started will allocate more memory every 1000 ms until failing due to
// exceeding the configured limit. The cluster watcher will automatically restart the process.

$id = (int) (Cluster::getContextId() ?? getmypid());

$logger = new Logger('worker-' . $id);

// Creating a log handler in this way allows the script to be run in a cluster or standalone.
if (Cluster::isWorker()) {
    $logger->pushProcessor(new ClusterLogSerializationProcessor());
    $handler = Cluster::createLogHandler();
} else {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter());
}

$logger->pushHandler($handler);

$buffer = "";
$character = "🍺";

$watcher = EventLoop::repeat(1, static function () use (&$buffer, $character, $logger, $id): void {
    $allocationSize = random_int(2 ** 20, 2 ** 24);
    $buffer .= str_repeat($character, $allocationSize);
    $logger->info(sprintf("Worker #%d is now using %d bytes of memory", $id, memory_get_usage(true)));
});

$logger->info(sprintf("Worker %d started", $id));

Cluster::awaitTermination();

$logger->info("Received termination request");

EventLoop::cancel($watcher);
