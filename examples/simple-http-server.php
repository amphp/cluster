<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

// This example requires amphp/http-server to be installed.

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\InternetAddress;
use Monolog\Logger;

// Run using bin/cluster examples/simple-http-server.php
// Test using your browser by connecting to http://localhost:8080/

// Creating a log handler in this way allows the script to be run in a cluster or standalone.
if (Cluster::isWorker()) {
    $handler = Cluster::createLogHandler();
} else {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter);
}

$logger = new Logger('worker-' . Cluster::getContextId());
$logger->pushHandler($handler);

// Set up a simple request handler.
$server = new SocketHttpServer($logger, socketServerFactory: Cluster::getSocketServerFactory());

$server->expose(new InternetAddress("127.0.0.1", 1337));
//$server->expose(new InternetAddress("[::]", 1337));

// Start the HTTP server
$server->start(
    new ClosureRequestHandler(function (): Response {
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8",
        ], "Hello, World!");
    }),
    new DefaultErrorHandler(),
);

// Stop the server when the worker is terminated.
Cluster::awaitTermination();

$server->stop();
