<?php

namespace Amp\Cluster;

use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;
use function Amp\asyncCall;
use function Amp\call;

final class Cluster
{
    /** @var Internal\IpcClient */
    private static $client;

    /** @var callable[]|null */
    private static $onClose;

    /** @var callable[][] */
    private static $onMessage = [];

    /** @var string[] */
    private static $signalWatchers;

    /**
     * @return bool
     */
    public static function isWorker(): bool
    {
        return self::$client !== null;
    }

    /**
     * @return int Returns the process ID or thread ID of the execution context.
     */
    public static function getId(): int
    {
        return \defined("AMP_CONTEXT_ID") ? \AMP_CONTEXT_ID : \getmypid();
    }

    /**
     * @param string                  $uri
     * @param Socket\BindContext|null $listenContext
     *
     * @return Promise
     */
    public static function listen(string $uri, ?Socket\BindContext $listenContext = null): Promise
    {
        return call(static function () use ($uri, $listenContext): \Generator {
            if (!self::isWorker()) {
                return Server::listen($uri, $listenContext);
            }

            $listenContext = $listenContext ?? new Socket\BindContext;

            if (canReusePort()) {
                $position = \strrpos($uri, ":");
                $port = $position ? (int) \substr($uri, $position) : 0;

                if ($port === 0) {
                    $uri = yield self::$client->selectPort($uri);
                }

                $listenContext = $listenContext->withReusePort();
                return Server::listen($uri, $listenContext);
            }

            $socket = yield self::$client->importSocket($uri);
            return self::listenOnBoundSocket($socket, $listenContext);
        });
    }

    /**
     * Attaches a callback to be invoked when a message is received from the parent process.
     *
     * @param string   $event
     * @param callable $callback
     */
    public static function onMessage(string $event, callable $callback): void
    {
        self::$onMessage[$event][] = $callback;
    }

    /**
     * @param string $event Event name.
     * @param mixed  $data Send data to the parent.
     *
     * @return Promise
     */
    public static function send(string $event, $data = null): Promise
    {
        if (!self::isWorker()) {
            return new Success; // Ignore sent messages when running as a standalone process.
        }

        return self::$client->send($event, $data);
    }

    /**
     * @param callable $callable Callable to invoke to shutdown the process.
     */
    public static function onTerminate(callable $callable): void
    {
        if (self::$onClose === null) {
            return;
        }

        if (self::$signalWatchers === null && !self::isWorker()) {
            self::$signalWatchers = [];

            try {
                $signalHandler = \Closure::fromCallable([self::class, 'stop']);
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
     * @param bool   $bubble Bubble flag for the IPC handler
     *
     * @return HandlerInterface
     *
     * @throws \Error Thrown if not running as a worker.
     */
    public static function createLogHandler(string $logLevel = LogLevel::DEBUG, bool $bubble = false): HandlerInterface
    {
        if (!self::isWorker()) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster." .
                "Use " . __CLASS__ . "::isWorker() to determine if the process is running as a worker");
        }

        return new Internal\IpcLogHandler(self::$client, $logLevel, $bubble);
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection Used by rebinding a Closure to this class.
     *
     * @param Channel                    $channel
     * @param Socket\ResourceSocket|null $socket
     *
     * @return Promise Resolved when the IPC client has terminated.
     */
    private static function run(Channel $channel, Socket\ResourceSocket $socket = null): Promise
    {
        self::$onClose = [];

        self::$client = new Internal\IpcClient(
            \Closure::fromCallable([self::class, 'handleMessage']),
            $channel,
            $socket
        );

        return call(static function (): \Generator {
            try {
                yield self::$client->run();
            } catch (\Throwable $exception) {
                // Exception rethrown below as part of MultiReasonException.
            }

            [$exceptions] = yield self::terminate();

            if (isset($exception)) {
                $exceptions[] = $exception;
            }

            try {
                yield self::$client->close();
            } catch (\Throwable $exception) {
                $exceptions[] = $exception;
            }

            self::$client = null;

            if ($count = \count($exceptions)) {
                if ($count === 1) {
                    $exception = $exceptions[0];
                    throw new ClusterException("Cluster worker failed: " . $exception->getMessage(), 0, $exception);
                }

                $exception = new MultiReasonException($exceptions);
                $message = \implode('; ', \array_map(function (\Throwable $exception): string {
                    return $exception->getMessage();
                }, $exceptions));
                throw new ClusterException("Cluster worker failed: " . $message, 0, $exception);
            }
        });
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * Used as the termination signal callback when not running as a cluster worker.
     *
     * @return Promise
     */
    private static function stop(): Promise
    {
        return call(static function (): \Generator {
            [$exceptions] = yield self::terminate();

            if ($count = \count($exceptions)) {
                if ($count === 1) {
                    $exception = $exceptions[0];
                    throw new ClusterException("Cluster worker failed: " . $exception->getMessage(), 0, $exception);
                }

                $exception = new MultiReasonException($exceptions);
                $message = \implode('; ', \array_map(function (\Throwable $exception): string {
                    return $exception->getMessage();
                }, $exceptions));
                throw new ClusterException("Stopping failed: " . $message, 0, $exception);
            }
        });
    }

    /**
     * Invokes any termination callbacks.
     *
     * @return Promise
     */
    private static function terminate(): Promise
    {
        if (self::$onClose === null) {
            return Promise\any([]);
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

        return Promise\any($promises);
    }

    /**
     * Internal callback triggered when a message is received from the parent.
     *
     * @param string $event
     * @param mixed  $data
     */
    private static function handleMessage(string $event, $data): void
    {
        foreach (self::$onMessage[$event] ?? [] as $callback) {
            asyncCall($callback, $data);
        }
    }

    /**
     * @param resource           $socket Socket resource (not a stream socket resource).
     * @param Socket\BindContext $listenContext
     *
     * @return Server
     */
    private static function listenOnBoundSocket($socket, Socket\BindContext $listenContext): Server
    {
        $context = $listenContext->toStreamContextArray();

        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        /** @var resource $stream */
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        $stream = \socket_export_stream($socket);
        \stream_context_set_option($stream, $context); // put eventual options like ssl back (per worker)

        return new Server($stream);
    }
}
