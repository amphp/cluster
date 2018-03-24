<?php

namespace Amp\Cluster;

use Amp\ByteStream\ResourceOutputStream;
use Amp\CallableMaker;
use Amp\Cluster\Internal\IpcClient;
use Amp\Emitter;
use Amp\Iterator;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\MultiReasonException;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Monolog\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Psr\Log\LogLevel;
use function Amp\asyncCall;
use function Amp\call;

final class Cluster {
    use CallableMaker;

    /** @var IpcClient */
    private static $client;

    /** @var callable[]|null */
    private static $onClose = [];

    /** @var callable[][] */
    private static $onMessage = [];

    /**
     * @param Channel             $channel
     * @param Socket\ClientSocket $socket
     */
    private static function init(Channel $channel, Socket\ClientSocket $socket) {
        self::$client = new IpcClient($channel, $socket, self::callableFromStaticMethod("onReceivedMessage"));
        self::$client->run()->onResolve(self::callableFromStaticMethod("terminate"));
    }

    /**
     * Invokes any termination callbacks.
     *
     * @return Promise|null
     */
    private static function terminate() { /* ?Promise */
        if (self::$onClose === null) {
            return null;
        }

        if (self::$client !== null) {
            self::$client = null;
        }

        $onClose = self::$onClose;
        self::$onClose = null;

        $promises = [];
        foreach ($onClose as $callable) {
            $promises[] = call($callable);
        }
        return Promise\all($promises);
    }

    /**
     * @return bool
     */
    public static function isWorker(): bool {
        return self::$client !== null;
    }

    /**
     * @param string                          $uri
     * @param Socket\ServerListenContext|null $listenContext
     * @param Socket\ServerTlsContext|null    $tlsContext
     *
     * @return Promise
     */
    public static function listen(
        string $uri,
        Socket\ServerListenContext $listenContext = null,
        Socket\ServerTlsContext $tlsContext = null
    ): Promise {
        return call(function () use ($uri, $listenContext, $tlsContext) {
            if (!self::isWorker()) {
                return Socket\listen($uri, $listenContext, $tlsContext);
            }

            $listenContext = $listenContext ?? new Socket\ServerListenContext;

            if (canReusePort()) {
                $position = \strrpos($uri, ":");
                $port = $position ? (int) \substr($uri, $position) : 0;

                if ($port === 0) {
                    $uri = yield self::$client->selectPort($uri);
                }

                $listenContext = $listenContext->withReusePort();
                return Socket\listen($uri, $listenContext, $tlsContext);
            }

            $socket = yield self::$client->importSocket($uri);
            return self::listenOnBoundSocket($socket, $listenContext, $tlsContext);
        });
    }

    /**
     * Internal callback triggered when a message is received from the parent.
     *
     * @param mixed $data
     */
    private static function onReceivedMessage(string $event, $data) {
        foreach (self::$onMessage[$event] ?? [] as $callback) {
            asyncCall($callback, $data);
        }
    }

    /**
     * Attaches a callback to be invoked when a message is received from the parent process.
     *
     * @param callable $callback
     */
    public static function onMessage(string $event, callable $callback) {
        self::$onMessage[$event][] = $callback;
    }

    /**
     * @param string $event Event name.
     * @param mixed  $data Send data to the parent.
     *
     * @return Promise
     */
    public static function send(string $event, $data = null): Promise {
        if (!self::isWorker()) {
            return new Success; // Ignore sent messages when running as a standalone process.
        }

        return self::$client->send($event, $data);
    }

    /**
     * @param callable $callable Callable to invoke to shutdown the process.
     */
    public static function onTerminate(callable $callable) {
        if (!self::isWorker()) {
            return;
        }

        if (self::$onClose === null) {
            return;
        }

        self::$onClose[] = $callable;
    }

    /**
     * @param resource                        $socket Socket resource (not a stream socket resource).
     * @param Socket\ServerListenContext|null $listenContext
     * @param Socket\ServerTlsContext|null    $tlsContext
     *
     * @return Server
     */
    private static function listenOnBoundSocket(
        $socket,
        Socket\ServerListenContext $listenContext,
        Socket\ServerTlsContext $tlsContext = null
    ): Server {
        if ($tlsContext) {
            $context = \array_merge(
                $listenContext->toStreamContextArray(),
                $tlsContext->toStreamContextArray()
            );
        } else {
            $context = $listenContext->toStreamContextArray();
        }

        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        $socket = \socket_export_stream($socket);
        \stream_context_set_option($socket, $context); // put eventual options like ssl back (per worker)

        return new Server($socket);
    }
}
