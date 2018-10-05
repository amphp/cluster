<?php

/** @noinspection PhpUndefinedClassInspection CallableMaker */

namespace Amp\Cluster;

use Amp\CallableMaker;
use Amp\Cluster\Internal\IpcClient;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Monolog\Handler\HandlerInterface;
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

    /** @var string[] */
    private static $signalWatchers;

    /* @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Channel             $channel
     * @param Socket\ClientSocket $socket
     */
    private static function init(Channel $channel, Socket\ClientSocket $socket) {
        /** @noinspection PhpDeprecationInspection */
        self::$client = new IpcClient($channel, $socket, self::callableFromStaticMethod("onReceivedMessage"));

        asyncCall(static function () {
            yield self::$client->run();
            yield self::terminate();
            yield self::$client->close();

            self::$client = null;

            Loop::stop();
        });
    }

    /**
     * Invokes any termination callbacks.
     *
     * @return Promise
     */
    private static function terminate(): Promise {
        if (self::$onClose === null) {
            return new Success;
        }

        if (self::$signalWatchers) {
            foreach (self::$signalWatchers as $watcher) {
                Loop::cancel($watcher);
            }
        }

        $onClose = self::$onClose;
        self::$onClose = null;

        $promises = [];
        foreach ($onClose as $callable) {
            $promises[] = call($callable);
        }

        $promise = Promise\all($promises);

        if (!self::isWorker()) {
            $promise->onResolve(function () {
                Loop::stop();
            });
        }

        return $promise;
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

    /* @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * Internal callback triggered when a message is received from the parent.
     *
     * @param string $event
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
     * @param string $event
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
        if (self::$onClose === null) {
            return;
        }

        if (self::$signalWatchers === null && !self::isWorker()) {
            self::$signalWatchers = [];

            try {
                /** @noinspection PhpDeprecationInspection */
                $signalHandler = self::callableFromStaticMethod('terminate');
                self::$signalWatchers[] = Loop::onSignal(\defined('SIGINT') ? \SIGINT : 2, $signalHandler);
                self::$signalWatchers[] = Loop::onSignal(\defined('SIGTERM') ? \SIGTERM : 15, $signalHandler);

                foreach (self::$signalWatchers as $signalWatcher) {
                    Loop::unreference($signalWatcher);
                }
            } catch (Loop\UnsupportedFeatureException $e) {
                // ignore if extensions are missing or OS is Windows
            }
        }

        self::$onClose[] = $callable;
    }

    /**
     * Creates a log handler in worker processes that communicates log messages to the parent.
     *
     * @param string $logLevel Log level for the IPC handler
     * @param bool   $bubble   Bubble flag for the IPC handler
     *
     * @return HandlerInterface
     *
     * @throws \Error Thrown if not running as a worker.
     */
    public static function createLogHandler(string $logLevel = LogLevel::DEBUG, bool $bubble = false): HandlerInterface {
        if (!self::isWorker()) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster");
        }

        return new Internal\IpcLogHandler($logLevel, $bubble);
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

        /** @var resource $stream */
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        $stream = \socket_export_stream($socket);
        \stream_context_set_option($stream, $context); // put eventual options like ssl back (per worker)

        return new Server($stream);
    }
}
