<p align="center">
<a href="https://github.com/amphp/cluster"><img src="https://raw.githubusercontent.com/amphp/logo/master/repos/cluster.png?v=2019-09-26" alt="cluster"/></a>
</p>

This package provides tools to run multiple PHP processes that can listen on the same port, such as multiple [HTTP server](https://github.com/amphp/http-server) instances listening on the same port. Port-sharing is achieved with `SO_REUSEPORT` where available and socket transfers otherwise.

Additionally, this package provides a mechanism to gracefully restart such processes with zero downtime. The entire cluster may be manually restarted or failed workers will automatically be respawned by the cluster watcher process.

## Usage

Instead of starting the processes with `php server.php`, cluster-enabled applications are started with `php vendor/bin/cluster server.php`.

A graceful restart can be initiated by sending a `USR1` signal to the cluster process.

Cluster-enabled applications need only replace a few components with those provided by this package.

#### Creating a Server

`Amp\Socket\Server::listen()` is usually used to create an instance of `Amp\Socket\Server` for accepting client connections.

Instead, `Amp\Cluster\Cluster::listen()` returns a promise that resolves to an instance of `Amp\Socket\Server`. `Cluster::listen()` allows addresses and ports to be shared across multiple PHP processes (or threads).

#### Logging

Log entries may be send to the cluster watcher to be logged to a single stream by using `Amp\Cluster\Cluster::createLogHandler()`. This handler can be attached to a `Monolog\Logger` instance.

#### Process Termination

Often signals are used to gracefully shutdown a server when a `SIGTERM` signal is received by the process. Instead of `Amp\Loop::onSignal()`, cluster-enable applications should use `Amp\Cluster\Cluster::onTerminate()` to define functions that handle graceful shutdown when `SIGINT` or `SIGTERM` is received.

#### Hot Reload in IntelliJ / PHPStorm

IntelliJ's file watchers can be used as trigger to send the `USR1` signal to the cluster's watcher process automatically on every file save.
You need to write a PID file using `--pid-file /path/to/file.pid` when starting the cluster and then setup a file watcher in the settings using the following settings:

 - Program: `bash`
 - Arguments: `-c "if test -f ~/test-cluster.pid; then kill -10 $(cat ~/test-cluster.pid); fi"`

## Example HTTP Server

The example below (which can be found in the [examples](https://github.com/amphp/cluster/tree/master/examples) directory as [simple-http-server.php](https://github.com/amphp/cluster/blob/master/examples/simple-http-server.php)) uses [`amphp/http-server`](https://github.com/amphp/http-server) to create an HTTP server that can be run in any number of processes simultaneously.

```php
<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

// This example requires amphp/http-server to be installed.

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Promise;
use Monolog\Logger;

// Run using bin/cluster examples/simple-http-server.php
// Test using your browser by connecting to http://localhost:8080/

Amp\Loop::run(function () {
    // Create socket servers using the Cluster::listen() method to share ports across processes.
    // Cluster::listen() returns a promise, so yield the array to wait for all promises to resolve.
    $sockets = yield [
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

    $logger = new Logger('worker-' . Cluster::getContextId());
    $logger->pushHandler($handler);

    // Set up a simple request handler.
    $server = new Server($sockets, new CallableRequestHandler(function (Request $request): Response {
        return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8"
        ], "Hello, World!");
    }), $logger);

    // Start the HTTP server
    yield $server->start();

    // Stop the server when the worker is terminated.
    Cluster::onTerminate(function () use ($server): Promise {
        return $server->stop();
    });
});
```
