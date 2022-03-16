<?php

namespace Amp\Cluster;

use Amp\CompositeException;
use Amp\DeferredFuture;
use Amp\Socket;
use Amp\Sync\Channel;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use function Amp\async;

final class Cluster
{
    private static ?Internal\IpcClient $client = null;

    private static bool $terminated = false;

    /** @var callable[][] */
    private static array $onMessage = [];

    /** @var string[]|null */
    private static ?array $signalWatchers = null;

    private static ?DeferredFuture $deferred = null;

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
     * @param Socket\SocketAddress $address
     * @param Socket\BindContext|null $bindContext
     *
     * @return Socket\SocketServer
     */
    public static function listen(
        Socket\SocketAddress $address,
        ?Socket\BindContext $bindContext = null
    ): Socket\SocketServer {
        if (!self::isWorker()) {
            return Socket\listen($address, $bindContext);
        }

        $bindContext = $bindContext ?? new Socket\BindContext;

        if (canReusePort()) {
            $position = \strrpos($address, ":");
            $port = $position ? (int) \substr($address, $position) : 0;

            if ($port === 0) {
                $address = self::$client->selectPort($address->toString());
            }

            $bindContext = $bindContext->withReusePort();
            return Socket\listen($address, $bindContext);
        }

        $socket = self::$client->importSocket($address);
        return self::listenOnBoundSocket($socket, $bindContext);
    }

    /**
     * Attaches a callback to be invoked when a message is received from the parent process.
     *
     * @param string $event
     * @param callable $callback
     */
    public static function onMessage(string $event, callable $callback): void
    {
        self::$onMessage[$event][] = $callback;
    }

    /**
     * @param string $event Event name.
     * @param mixed $data Send data to the parent.
     */
    public static function send(string $event, $data = null): void
    {
        if (!self::isWorker()) {
            return; // Ignore sent messages when running as a standalone process.
        }

        self::$client->send($event, $data);
    }

    public static function awaitTermination(): void
    {
        if (self::$deferred === null) {
            self::$deferred = new DeferredFuture;
        }

        if (self::$signalWatchers === null) {
            self::$signalWatchers = [
                'SIGINT' => \defined('SIGINT') ? \SIGINT : 2,
                'SIGQUIT' => \defined('SIGQUIT') ? \SIGQUIT : 3,
                'SIGTERM' => \defined('SIGTERM') ? \SIGTERM : 15,
                'SIGSTOP' => \defined('SIGSTOP') ? \SIGSTOP : 19,
            ];

            try {
                self::$signalWatchers = \array_map(static function (int $signo): string {
                    $watcher = EventLoop::onSignal($signo, self::stop(...));
                    EventLoop::unreference($watcher);
                    return $watcher;
                }, self::$signalWatchers);
            } catch (UnsupportedFeatureException $e) {
                // ignore if extensions are missing or OS is Windows
            }
        }

        self::$deferred->getFuture()->await();
    }

    /**
     * Creates a log handler in worker processes that communicates log messages to the parent.
     *
     * @param string $logLevel Log level for the IPC handler
     * @param bool $bubble Bubble flag for the IPC handler
     *
     * @return HandlerInterface
     *
     * @throws \Error Thrown if not running as a worker.
     */
    public static function createLogHandler(string $logLevel = LogLevel::DEBUG, bool $bubble = false): HandlerInterface
    {
        if (!self::isWorker()) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster. " .
                "Use " . __CLASS__ . "::isWorker() to determine if the process is running as a worker.");
        }

        return new Internal\IpcLogHandler(self::$client, $logLevel, $bubble);
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection Used by rebinding a Closure to this class.
     *
     * @param Channel $channel
     * @param Socket\ResourceSocket|null $socket
     */
    private static function run(Channel $channel, ?Socket\ResourceSocket $socket = null): void
    {
        self::$client = new Internal\IpcClient(
            self::handleMessage(...),
            $channel,
            $socket
        );

        $exceptions = [];

        try {
            self::$client->run();
        } catch (\Throwable $exception) {
            // Exception rethrown below as part of MultiReasonException.
            $exceptions[] = $exception;
        }

        try {
            self::$client->close();
        } catch (\Throwable $exception) {
            $exceptions[] = $exception;
        }

        if ($count = \count($exceptions)) {
            if ($count === 1) {
                $exception = $exceptions[0];
                throw new ClusterException("Cluster worker failed: " . $exception->getMessage(), 0, $exception);
            }

            $exception = new CompositeException($exceptions);
            $message = \implode('; ', \array_map(function (\Throwable $exception): string {
                return $exception->getMessage();
            }, $exceptions));
            throw new ClusterException("Cluster worker failed: " . $message, 0, $exception);
        }
    }

    /**
     * Invokes any termination callbacks.
     */
    public static function stop(): void
    {
        if (self::$terminated) {
            return;
        }

        if (self::$signalWatchers) {
            foreach (self::$signalWatchers as $watcher) {
                EventLoop::cancel($watcher);
            }
        }

        if (self::$deferred === null) {
            self::$deferred = new DeferredFuture;
        }

        self::$deferred->complete();
    }

    /**
     * Internal callback triggered when a message is received from the parent.
     *
     * @param string $event
     * @param mixed $data
     */
    private static function handleMessage(string $event, $data): void
    {
        foreach (self::$onMessage[$event] ?? [] as $callback) {
            async($callback, $data);
        }
    }

    /**
     * @param resource $socket Socket resource (not a stream socket resource).
     */
    private static function listenOnBoundSocket($socket, Socket\BindContext $bindContext): Socket\SocketServer
    {
        $context = $bindContext->toStreamContextArray();

        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        /** @var resource $stream */
        $stream = \socket_export_stream($socket);
        \stream_context_set_option($stream, $context); // put eventual options like ssl back (per worker)

        return new Socket\ResourceSocketServer($stream, $bindContext);
    }
}
