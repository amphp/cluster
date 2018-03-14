<?php

namespace Amp\Cluster\Internal;

use Amp\ByteStream\InMemoryStream;
use Amp\Cluster\IpcLogger;
use Amp\Cluster\Listener;
use Amp\Emitter;
use Amp\Parallel\Sync\ChannelledSocket;
use Amp\Parallel\Sync\ChannelledStream;
use Amp\Socket;
use function Amp\call;

return function (ChannelledSocket $receiveChannel) use ($argc, $argv) {
    // Remove this scripts path from process arguments.
    \array_shift($argv);
    --$argc;

    if (!isset($argv[0])) {
        throw new \Error("No socket path provided");
    }

    /** @var \Amp\Socket\ClientSocket $socket */
    $socket = yield Socket\connect(\array_shift($argv));
    --$argc;

    $emitter = new Emitter;
    $sendChannel = new ChannelledStream(new InMemoryStream, $socket); // Channel is write-only.
    $listener = new Listener($socket, $sendChannel);
    $logger = new IpcLogger($sendChannel);

    // Protect current scope by requiring script within another function.
    $init = (function () use ($argc, $argv): callable {
        if (!isset($argv[0])) {
            throw new \Error("No script path given");
        }

        if (!\is_file($argv[0])) {
            throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
        }

        $callable = require $argv[0];

        if (!\is_callable($callable)) {
            throw new \Error("Script did not return a callable function");
        }

        return $callable;
    })();

    $stop = yield call($init, $listener, $logger, $emitter->iterate());

    if (!\is_callable($stop)) {
        throw new \Error("Cluster initialization callable did not return a stop callable");
    }

    try {
        while (($value = $receiveChannel->receive()) !== null) {
            yield $emitter->emit($value);
        }

        $emitter->complete();
    } catch (\Throwable $exception) {
        $emitter->fail($exception);
    }

    try {
        yield call($stop);
        yield $sendChannel->send(null);
    } finally {
        $socket->close();
        $receiveChannel->close();
    }
};
