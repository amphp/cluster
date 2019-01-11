<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Cluster;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket;
use Amp\TimeoutCancellationToken;

return function (Channel $channel) use ($argc, $argv) {
    // Remove this scripts path from process arguments.
    --$argc;
    \array_shift($argv);

    if (!isset($argv[0])) {
        throw new \Error("No socket path provided");
    }

    // Remove socket path from process arguments.
    --$argc;
    $uri = \array_shift($argv);

    try {
        $transferSocket = yield Socket\connect($uri, null, new TimeoutCancellationToken(5000));
    } catch (\Throwable $exception) {
        throw new \RuntimeException("Could not connect to IPC socket");
    }

    $promise = (function () use ($channel, $transferSocket): Promise {
        return static::run($channel, $transferSocket);
    })->bindTo(null, Cluster::class)();

    if (!isset($argv[0])) {
        throw new \Error("No script path given");
    }

    if (!\is_file($argv[0])) {
        throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
    }

    // Protect current scope by requiring script within another function.
    (function () use ($argc, $argv) { // Using $argc so it is available to the required script.
        require $argv[0];
    })();

    return yield $promise;
};
