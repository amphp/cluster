<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Cluster;
use Amp\Cluster\Watcher;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\ResourceSocket;
use Amp\TimeoutCancellationToken;

return static function (Channel $channel) use ($argc, $argv) {
    // Remove this scripts path from process arguments.
    --$argc;
    \array_shift($argv);

    if (!isset($argv[0])) {
        throw new \Error("No socket path provided");
    }

    // Remove socket path from process arguments.
    --$argc;
    $uri = \array_shift($argv);

    $transferSocket = null;

    if ($uri !== Watcher::EMPTY_URI) {
        // Read random key from process channel and send back to parent over transfer socket to authenticate.
        $key = yield $channel->receive();

        try {
            $transferSocket = yield Socket\connect($uri, null, new TimeoutCancellationToken(Watcher::WORKER_TIMEOUT));
        } catch (\Throwable $exception) {
            throw new \RuntimeException("Could not connect to IPC socket", 0, $exception);
        }

        \assert($transferSocket instanceof ResourceSocket);

        yield $transferSocket->write($key);
    }

    $promise = (static function () use ($channel, $transferSocket): Promise {
        /** @noinspection PhpUndefinedClassInspection */
        return static::run($channel, $transferSocket);
    })->bindTo(null, Cluster::class)();

    if (!isset($argv[0])) {
        throw new \Error("No script path given");
    }

    if (!\is_file($argv[0])) {
        throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
    }

    // Protect current scope by requiring script within another function.
    (static function () use ($argc, $argv): void { // Using $argc so it is available to the required script.
        /** @noinspection PhpIncludeInspection */
        require $argv[0];
    })();

    return yield $promise;
};
