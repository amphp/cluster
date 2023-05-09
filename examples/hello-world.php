<?php declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\InternetAddress;
use Monolog\Logger;
use function Amp\async;

// Run using bin/cluster examples/hello-world.php
// Then connect using `nc localhost 1337` multiple times to see the PID of the accepting process change.

$socketFactory = Cluster::getServerSocketFactory();

$server = $socketFactory->listen(new InternetAddress("127.0.0.1", 1337));

$id = Cluster::getContextId();

// Creating a log handler in this way allows the script to be run in a cluster or standalone.
if (Cluster::isWorker()) {
    $handler = Cluster::createLogHandler();
} else {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter);
}

$logger = new Logger('worker-' . $id);
$logger->pushHandler($handler);

$logger->info(sprintf("Listening on %s in PID %s", $server->getAddress(), $id));

async(static function () use ($server, $logger): void {
    Cluster::awaitTermination();
    $logger->info("Received termination request");
    $server->close();
});

while ($client = $server->accept()) {
    $logger->info(sprintf("Accepted client on %s in PID %d", $server->getAddress(), $id));

    $client->write(sprintf("Hello from worker ID %d!\n", $id));
    $client->end();
}
