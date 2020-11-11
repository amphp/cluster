<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

// This example requires amphp/http-server to be installed.

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;

// Run using bin/cluster examples/simple-http-server.php
// Test using your browser by connecting to http://localhost:8080/

// Create socket servers using the Cluster::listen() method to share ports across processes.
// Cluster::listen() returns a promise, so yield the array to wait for all promises to resolve.
$sockets = [
    Cluster::listen("0.0.0.0:8080"),
    Cluster::listen("[::]:8080"),
];

// Creating a log handler in this way allows the script to be run in a cluster or standalone.
if (Cluster::isWorker()) {
    $handler = Cluster::createLogHandler();
} else {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter);
}

$logger = new Logger('worker-' . Cluster::getId());
$logger->pushHandler($handler);

// Set up a simple request handler.
$server = new HttpServer($sockets, new CallableRequestHandler(function (Request $request): Response {
    return new Response(Status::OK, [
        "content-type" => "text/plain; charset=utf-8"
    ], "Hello, World!");
}), $logger);

// Start the HTTP server
$server->start();

// Stop the server when the worker is terminated.
Cluster::awaitTermination();

$server->stop();
