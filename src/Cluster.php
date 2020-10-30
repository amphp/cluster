<?php

namespace Amp\Cluster;

use Amp\Deferred;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Parallel\Sync\Channel;
use Amp\Socket;
use Amp\Socket\Server;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;
use function Amp\await;
use function Amp\defer;

final class Cluster
{
    private static ?Internal\IpcClient $client = null;

    private static bool $terminated = false;

    /** @var callable[][] */
    private static array $onMessage = [];

    /** @var string[]|null */
    private static ?array $signalWatchers = null;

    private static ?Deferred $deferred = null;

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
     * @return Server
     */
    public static function listen(string $uri, ?Socket\BindContext $listenContext = null): Server
    {
        if (!self::isWorker()) {
            return Server::listen($uri, $listenContext);
        }

        $listenContext = $listenContext ?? new Socket\BindContext;

        if (canReusePort()) {
            $position = \strrpos($uri, ":");
            $port = $position ? (int) \substr($uri, $position) : 0;

            if ($port === 0) {
                $uri = self::$client->selectPort($uri);
            }

            $listenContext = $listenContext->withReusePort();
            return Server::listen($uri, $listenContext);
        }

        $socket = self::$client->importSocket($uri);
        return self::listenOnBoundSocket($socket, $listenContext);
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
            self::$deferred = new Deferred;
        }

        if (self::$signalWatchers === null) {
            self::$signalWatchers = [
                'SIGINT' => \defined('SIGINT') ? \SIGINT : 2,
                'SIGQUIT' => \defined('SIGQUIT') ? \SIGQUIT : 3,
                'SIGTERM' => \defined('SIGTERM') ? \SIGTERM : 15,
                'SIGSTOP' => \defined('SIGSTOP') ? \SIGSTOP : 19,
            ];

            try {
                self::$signalWatchers = \array_map(function (int $signo): string {
                    $watcher = Loop::onSignal($signo, [self::class, 'stop']);
                    Loop::unreference($watcher);
                    return $watcher;
                }, self::$signalWatchers);
            } catch (Loop\UnsupportedFeatureException $e) {
                // ignore if extensions are missing or OS is Windows
            }
        }

        await(self::$deferred->promise());
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
     */
    private static function run(Channel $channel, ?Socket\ResourceSocket $socket = null): void
    {
        self::$client = new Internal\IpcClient(
            \Closure::fromCallable([self::class, 'handleMessage']),
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

            $exception = new MultiReasonException($exceptions);
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
                Loop::cancel($watcher);
            }
        }

        if (self::$deferred === null) {
            self::$deferred = new Deferred;
        }

        self::$deferred->resolve();
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
            defer($callback, $data);
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
