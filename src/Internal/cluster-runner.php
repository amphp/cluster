<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Cluster;
use Amp\Cluster\Watcher;
use Amp\Future;
use Amp\Parallel\Ipc;
use Amp\Sync\Channel;
use Amp\TimeoutCancellation;
use function Amp\async;

return static function (Channel $channel) use ($argc, $argv): void {
    /** @var list<string> $argv */

    if (function_exists("posix_setsid")) {
        // Allow accepting signals (like SIGINT), without having signals delivered to the watcher impact the cluster
        \posix_setsid();
    }

    // Remove this scripts path from process arguments.
    --$argc;
    \array_shift($argv);

    if (!isset($argv[0])) {
        throw new \Error("No script path given");
    }

    if (!\is_file($argv[0])) {
        throw new \Error(\sprintf(
            "No script found at '%s' (be sure to provide the full path to the script)",
            $argv[0],
        ));
    }

    try {
        // Read random IPC hub URI and associated key from process channel.
        ['uri' => $uri, 'key' => $key] = $channel->receive();

        $transferSocket = Ipc\connect($uri, $key, new TimeoutCancellation(Watcher::WORKER_TIMEOUT));
    } catch (\Throwable $exception) {
        throw new \RuntimeException("Could not connect to IPC socket", 0, $exception);
    }

    try {
        (static fn() => Cluster::init($channel, $transferSocket))->bindTo(null, Cluster::class)();

        Future\await([
            async((static fn () => Cluster::run())->bindTo(null, Cluster::class)),

            /* Protect current scope by requiring script within another function.
             * Using $argc so it is available to the required script. */
            async(static function () use ($argc, $argv): void {
                require $argv[0];
            }),
        ]);
    } finally {
        $transferSocket->close();
        $channel->send(null);
    }
};
