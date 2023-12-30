# amphp/cluster

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/cluster` provides tools to transfer network sockets to independent PHP processes, as well as a lightweight framework to create a multiprocess server cluster.

## Requirements

- PHP 8.1+
- [`ext-sockets`](https://php.net/sockets)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/cluster
```

## Documentation

### Transferring Sockets

Clusters are built by transferring sockets from a parent process to child processes, each of which listen for connections and/or handles client sockets. This library provides low-level components which may be used independent of the Cluster framework. These components allow you to write your own server logic which transfers server sockets or client sockets to child processes to distribute load or group related clients.

#### Transferring Client Sockets

`ClientSocketReceivePipe` and `ClientSocketSendPipe` pair of classes are used to send client sockets from one PHP process to another over an existing IPC connection between the two processes.

The example below demonstrates creating a new child process in a parent, then establishing a new IPC socket between the parent and child. That socket is used to create a `ClientSocketSendPipe` in the parent and a corresponding `ClientSocketReceivePipe` in the child. The parent then creates a socket server and listens for connections. When a connection is received, the client socket is transferred to the child process where it is handled.

```php
// parent.php

use Amp\Cluster\ClientSocketSendPipe;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\Socket;
use Revolt\EventLoop;
use function Amp\Socket\listen;

$ipcHub = new LocalIpcHub();

// Sharing the IpcHub instance with the context factory isn't required,
// but reduces the number of opened sockets.
$contextFactory = new ProcessContextFactory(ipcHub: $ipcHub);

$context = $contextFactory->start(__DIR__ . '/child.php');

$connectionKey = $ipcHub->generateKey();
$context->send(['uri' => $ipcHub->getUri(), 'key' => $connectionKey]);

// $socket will be a bidirectional socket to the child.
$socket = $ipcHub->accept($connectionKey);

$socketPipe = new ClientSocketSendPipe($socket);

$server = listen('127.0.0.1:1337');

// Close server when SIGTERM is received.
EventLoop::onSignal(SIGTERM, $server->close(...));

$clientId = 0;
while ($client = $server->accept()) {
    // $clientId is an example of arbitrary data which may be
    // associated with a transferred socket.
    $socketPipe->send($client, ++$clientId);
}
```

```php
// child.php

use Amp\Cluster\ClientSocketReceivePipe;
use Amp\Socket\Socket;
use Amp\Sync\Channel;

return function (Channel $channel): void {
    ['uri' => $uri, 'key' => $connectionKey] = $channel->receive();

    // $socket will be a bidirectional socket to the parent.
    $socket = Amp\Parallel\Ipc\connect($uri, $connectionKey);

    $socketPipe = new ClientSocketReceivePipe($socket);

    while ($transferredSocket = $socketPipe->receive()) {
        // Handle client socket in a separate coroutine (fiber).
        async(
            function (Socket $client, int $id) { /* ... */ },
            $transferredSocket->getSocket(), // Transferred socket
            $transferredSocket->getData(), // Associated data
        );
    }
};
```

While this example is somewhat contrived as there would be little reason to send all clients to a single process, it is easy to extrapolate such an example to a parent process which load balances a set of children or distributes clients based on some other factor. Reading and writing may take place on the client socket in the parent before being transferred. For example, an HTTP server may establish a WebSocket connection before transferring the socket to a child process. See [`amphp/http-server`](https://github.com/amphp/http-server) and [`amphp/websocket-server`](https://github.com/amphp/websocket-server) for additional components to build such a server.

#### Transferring Server Sockets

The example below demonstrates creating a new child process in a parent, then establishing a new IPC socket between the parent and child. In the parent, the IPC socket is passed to `ServerSocketPipeProvider::provideFor()`, which listens for requests for server sockets on the IPC socket. In the child, the IPC socket is provided to an instance of `ServerSocketPipeFactory`. When the child creates a server socket using the `ServerSocketPipeFactory`, the server socket is created in the parent process, then sent to the child. If the parent created multiple children, any child that requested the same server socket would receive another reference to the same socket, allowing multiple children to listen on the same address and port. Incoming client connections are selected round-robin by the operating system. For greater control over client distribution, consider accepting clients in a single process and transferring client sockets to children instead.

`ServerSocketPipeFactory` implements `ServerSocketFactory`, allowing it to be used in place of factories which create the server socket within the same process.

```php
// parent.php

use Amp\CancelledException;
use Amp\Cluster\ClientSocketSendPipe;
use Amp\Cluster\ServerSocketPipeProvider;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\SignalCancellation;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Socket\listen;

$ipcHub = new LocalIpcHub();

$serverProvider = new ServerSocketPipeProvider();

// Sharing the IpcHub instance with the context factory isn't required,
// but reduces the number of opened sockets.
$contextFactory = new ProcessContextFactory(ipcHub: $ipcHub);

$context = $contextFactory->start(__DIR__ . '/child.php');

$connectionKey = $ipcHub->generateKey();
$context->send(['uri' => $ipcHub->getUri(), 'key' => $connectionKey]);

// $socket will be a bidirectional socket to the child.
$socket = $ipcHub->accept($connectionKey);

// Listen for requests for server sockets on the given socket until cancelled by signal.
try {
    $serverProvider->provideFor($socket, new SignalCancellation(SIGTERM));
} catch (CancelledException) {
    // Signal cancellation expected.
}
```

```php
// child.php

use Amp\Cluster\ClientSocketReceivePipe;
use Amp\Cluster\ServerSocketPipeFactory;
use Amp\Sync\Channel;

return function (Channel $channel): void {
    ['uri' => $uri, 'key' => $connectionKey] = $channel->receive();

    // $socket will be a bidirectional socket to the parent.
    $socket = Amp\Parallel\Ipc\connect($uri, $connectionKey);

    $serverFactory = new ServerSocketPipeFactory($socket);

    // Requests the server socket from the parent process.
    $server = $serverFactory->listen('127.0.0.1:1337');

    while ($client = $server->accept()) {
        // Handle client socket in a separate coroutine (fiber).
        async(function () use ($client) { /* ... */ });
    }
};
```

---

### Clusters

Clusters are created from specialized scripts using static methods of `Cluster` to create components which communicate with the parent watcher process when running as part of a cluster. Some `Cluster` methods may also be called when a script is run directly, returning a standalone component which does not require a watcher process. For example, `Cluster::getServerSocketFactory()` returns an instance which creates and transfers the server socket from the watcher process when running within a cluster, or instead returns a `ResourceSocketServerFactory` when running a script directly.

Cluster scripts can be run using the included executable `vendor/bin/cluster` from the command line or programmatically from within an application using the `ClusterWatcher` class.

```bash
vendor/bin/cluster --workers=4 path/to/script.php
```

When installed as a dependency to your project, the command above will start a cluster of 4 workers, each running the script at `path/to/script.php`.

Alternatively, your application can launch a cluster from code using `ClusterWatcher`.

```php
use Amp\Cluster\ClusterWatcher;
use Revolt\EventLoop;

$watcher = new ClusterWatcher('path/to/script.php');
$watcher->start(4); // Start cluster with 4 workers.

// Using a signal to stop the cluster for this example.
EventLoop::onSignal(SIGTERM, fn () => $watcher->stop());

foreach ($watcher->getMessageIterator() as $message) {
    // Handle received message from worker.
}
```

#### Creating a Server

Components in AMPHP which must use socket servers use instances of `Amp\Socket\SocketServerFactory` to create these socket severs. One such component is `Amp\Http\Server\SocketHttpServer` in [`amphp/http-server`](https://github.com/amphp/http-server). Within a cluster script, `Cluster::getServerSocketFactory()` should be used to create a socket factory which will either create the socket locally or requests the server socket from the cluster watcher.

The [example HTTP server](#example-http-server) below demonstrates using `Cluster::getServerSocketFactory()` to create an instance of `ServerSocketFactory` and provide it when creating a `SocketHttpServer`.

#### Logging

Log entries may be sent to the cluster watcher to be logged to a single stream by using `Cluster::createLogHandler()`. This handler can be attached to a `Monolog\Logger` instance. The [example HTTP server](#example-http-server) below creates a log handler depending upon if the script is a cluster worker or running as a standalone script.

`Cluster::createLogHandler()` may only be called when running the cluster script as part of a cluster. Use `Cluster::isWorker()` to check if the script is running as a cluster worker.

#### Process Termination

A cluster script may await termination from a signal (one of `SIGTERM`, `SIGINT`, `SIGQUIT`, or `SIGHUP`) by using `Cluster::awaitTermination()`.

#### Sending and Receiving Messages

The cluster watcher and workers may send serializable data to each other. The cluster watcher receives messages from workers via a concurrent iterator returned by `ClusterWatcher::getMessageIterator()`. The iterator emits instances of `ClusterWorkerMessage`, containing the data received and a reference to the `ClusterWorker` which sent the message which can be used to send a reply to only that worker. The cluster watcher may broadcast a message to all workers using `ClusterWatcher::broadcast()`.

Workers can send and receive messages using a `Channel` returned from `Cluster::getChannel()`. This method may only be called when running the cluster script as part of a cluster. Use `Cluster::isWorker()` to check if the script is running as a cluster worker.

#### Restarting

`ClusterWatcher::restart()` may be called at anytime to stop all existing workers and create new workers to replace those which were stopped. When using processes for workers (that is, not using threads via `ext-parallel`), the code running in the workers will be reloaded when the new process starts.

#### Hot Reload in IntelliJ / PhpStorm

When running the cluster using the cluster executable (`vendor/bin/cluster`), IntelliJ's file watchers can be used as trigger to send the `SIGUSR1` signal to the cluster's watcher process automatically on every file save.
You need to write a PID file using `--pid-file /path/to/file.pid` when starting the cluster and then set up a file watcher in the settings using the following settings:

 - Program: `bash`
 - Arguments: `-c "if test -f ~/test-cluster.pid; then kill -10 $(cat ~/test-cluster.pid); fi"`

## Example HTTP Server

The example below (which can be found in the [examples](https://github.com/amphp/cluster/tree/master/examples) directory as [simple-http-server.php](https://github.com/amphp/cluster/blob/master/examples/cluster/simple-http-server.php)) uses [`amphp/http-server`](https://github.com/amphp/http-server) to create an HTTP server that can be run in any number of processes simultaneously.

```php
<?php

require __DIR__ . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;

$id = Cluster::getContextId() ?? getmypid();

// Creating a log handler in this way allows the script to be run in a cluster or standalone.
if (Cluster::isWorker()) {
    $handler = Cluster::createLogHandler();
} else {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter());
}

$logger = new Logger('worker-' . $id);
$logger->pushHandler($handler);
$logger->useLoggingLoopDetection(false);

// Cluster::getServerSocketFactory() will return a factory which creates the socket
// locally or requests the server socket from the cluster watcher.
$socketFactory = Cluster::getServerSocketFactory();
$clientFactory = new SocketClientFactory($logger);

$httpServer = new SocketHttpServer($logger, $socketFactory, $clientFactory);
$httpServer->expose('127.0.0.1:1337');

// Start the HTTP server
$httpServer->start(
    new ClosureRequestHandler(function (): Response {
        return new Response(HttpStatus::OK, [
            "content-type" => "text/plain; charset=utf-8",
        ], "Hello, World!");
    }),
    new DefaultErrorHandler(),
);

// Stop the server when the cluster watcher is terminated.
Cluster::awaitTermination();

$server->stop();
```

## Versioning

`amphp/cluster` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
