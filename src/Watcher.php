<?php

namespace Amp\Cluster;

use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Monolog\Logger;
use function Amp\async;

final class Watcher
{
    public const WORKER_TIMEOUT = 5;
    public const KEY_LENGTH = 32;
    public const EMPTY_URI = '~';

    /** @var resource[] */
    private array $sockets = [];

    private bool $running = false;

    /** @var string[] */
    private array $script;

    private Logger $logger;

    private int $nextId = 1;

    private Internal\WorkerHub $hub;

    private \SplObjectStorage $workers;

    /** @var callable[][] */
    private array $onMessage = [];

    private ?Deferred $deferred = null;

    /**
     * @param string|string[]  $script Script path and optional arguments.
     * @param Logger $logger
     */
    public function __construct(string|array $script, Logger $logger)
    {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        $canReusePort = canReusePort();

        if (!$canReusePort && !\extension_loaded("sockets")) {
            throw new \Error("The sockets extension is required to create clusters on this system");
        }

        $this->logger = $logger;

        if (!$canReusePort) {
            $this->hub = new Internal\WorkerHub;
        }

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php', $this->hub ? $this->hub->getUri() : self::EMPTY_URI],
            \is_array($script) ? \array_values(\array_map('strval', $script)) : [(string) $script]
        );

        $this->workers = new \SplObjectStorage;
    }

    public function __destruct()
    {
        if ($this->running) {
            $this->stop();
        }
    }

    /**
     * Attaches a callback to be invoked when a message is received from a worker process.
     *
     * @param string   $event
     * @param callable $callback
     */
    public function onMessage(string $event, callable $callback): void
    {
        $this->onMessage[$event][] = $callback;
    }

    /**
     * @param int $count Number of cluster workers to spawn.
     */
    public function start(int $count): void
    {
        if ($this->running || $this->deferred) {
            throw new \Error("The cluster is already running or has already run");
        }

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->deferred = new Deferred;
        $this->running = true;

        try {
            $promises = [];
            for ($i = 0; $i < $count; ++$i) {
                $promises[] = $this->startWorker();
            }
            await($promises);
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    private function startWorker(): Promise
    {
        return async(function (): void {
            if (Parallel::isSupported()) {
                $context = new Parallel($this->script);
            } else {
                $context = new Process($this->script);
            }

            $context->start();

            $id = $this->nextId++;

            if ($this->hub !== null) {
                $key = $this->hub->generateKey($id, self::KEY_LENGTH);

                $context->send($key);

                try {
                    $socket = $this->hub->accept($id);
                } catch (\Throwable $exception) {
                    if ($context->isRunning()) {
                        $context->kill();
                    }

                    throw new ClusterException("Starting the cluster worker failed", 0, $exception);
                }
            }

            $this->logger->info(\sprintf('Started worker with ID %d', $id));

            $worker = new Internal\IpcParent(
                $context,
                $this->logger,
                \Closure::fromCallable([$this, 'bindSocket']),
                \Closure::fromCallable([$this, 'handleMessage']),
                $socket ?? null
            );

            if ($context instanceof Process) {
                $stdout = async(function () use ($context): void {
                    $stream = $context->getStdout();
                    $stream->unreference();
                    while (null !== $chunk = $stream->read()) {
                        $this->logger->info(\sprintf('STDOUT from PID %d: %s', $context->getPid(), $chunk));
                    }
                });

                $stderr = async(function () use ($context): void {
                    $stream = $context->getStderr();
                    $stream->unreference();
                    while (null !== $chunk = $stream->read()) {
                        $this->logger->error(\sprintf('STDERR from PID %d: %s', $context->getPid(), $chunk));
                    }
                });

                $promise = Promise\all([$stdout, $stderr]);
            } else {
                $promise = new Success;
            }

            $runner = $worker->run();

            $promise = async(function () use ($worker, $context, $id, $runner, $promise) {
                try {
                    try {
                        await($runner); // Wait for worker to exit.
                        $this->logger->info("Worker {$id} terminated cleanly" .
                            ($this->running ? ", restarting..." : ""));
                    } catch (ChannelException $exception) {
                        $this->logger->error("Worker {$id} died unexpectedly" .
                            ($this->running ? ", restarting..." : ""));
                    } catch (ContextException $exception) {
                        $this->logger->error("Worker {$id} died unexpectedly" .
                            ($this->running ? ", restarting..." : ""));
                    } catch (\Throwable $exception) {
                        $this->logger->error("Worker {$id} failed: " . (string) $exception);
                        throw $exception;
                    } finally {
                        if ($context->isRunning()) {
                            $context->kill();
                        }

                        $this->workers->detach($worker);
                    }

                    if ($this->running) {
                        await($this->startWorker());
                    }

                    // Wait for the STDIO streams to be consumed and closed.
                    await($promise);
                } catch (\Throwable $exception) {
                    $this->stop();
                    throw $exception;
                }
            });

            if (!$this->running) {
                // Cluster stopped while worker was starting, so immediately shutdown the worker.
                $worker->shutdown();
                return;
            }

            $this->workers->attach($worker, [$context, $promise]);
        });
    }

    /**
     * Returns a promise that is succeeds when the cluster has stopped or fails if a worker cannot be restarted or
     * if stopping the cluster fails.
     */
    public function join(): void
    {
        if (!$this->deferred) {
            throw new \Error("The cluster has not been started");
        }

        await($this->deferred->promise());
    }

    public function restart(): void
    {
        $promises = [];
        foreach (clone $this->workers as $worker) {
            \assert($worker instanceof Internal\IpcParent);
            [$context, $promise] = $this->workers[$worker];
            \assert($context instanceof Context);

            $promises[] = async(function () use ($worker, $context, $promise): \Generator {
                try {
                    $worker->shutdown();
                    await(Promise\timeout($promise, self::WORKER_TIMEOUT));
                } finally {
                    if ($context->isRunning()) {
                        $context->kill();
                    }
                }
            });
        }

        try {
            await($promises);
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    /**
     * Stops the cluster.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        $promise = async(function (): void {
            $promises = [];
            foreach (clone $this->workers as $worker) {
                \assert($worker instanceof Internal\IpcParent);
                $promises[] = async(function () use ($worker): void {
                    [$context, $promise] = $this->workers[$worker];
                    \assert($context instanceof Context);

                    try {
                        $worker->shutdown();
                        await(Promise\timeout($promise, self::WORKER_TIMEOUT));
                    } catch (ContextException $exception) {
                        // Ignore if the worker has already died unexpectedly.
                    } finally {
                        if ($context->isRunning()) {
                            $context->kill();
                        }
                    }
                });
            }

            foreach ($this->sockets as $socket) {
                \fclose($socket);
            }

            [$exceptions] = await(Promise\any($promises));

            $this->workers = new \SplObjectStorage;

            if ($count = \count($exceptions)) {
                if ($count === 1) {
                    $exception = \current($exceptions);
                    throw new ClusterException("Stopping the cluster failed: " . $exception->getMessage(), 0, $exception);
                }

                $exception = new MultiReasonException($exceptions);
                $message = \implode('; ', \array_map(static function (\Throwable $exception): string {
                    return $exception->getMessage();
                }, $exceptions));
                throw new ClusterException("Stopping the cluster failed: " . $message, 0, $exception);
            }
        });

        $this->deferred->resolve($promise);
    }

    /**
     * Broadcast data to all workers, triggering any callbacks registered with Cluster::onMessage().
     *
     * @param mixed $data
     *
     * @return Promise Resolved once data has been sent to all workers.
     */
    public function broadcast(string $event, $data = null): Promise
    {
        $promises = [];
        /** @var Internal\IpcParent $worker */
        foreach ($this->workers as $worker) {
            $promises[] = $worker->send($event, $data);
        }
        return Promise\all($promises);
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @param string $uri
     *
     * @return resource Stream socket server resource.
     */
    private function bindSocket(string $uri) /* : resource */
    {
        if (isset($this->sockets[$uri])) {
            return $this->sockets[$uri];
        }

        if (!\strncmp($uri, "unix://", 7)) {
            @\unlink(\substr($uri, 7));
        }

        $context = \stream_context_create([
            "socket" => [
                "so_reuseaddr" => \stripos(PHP_OS, "WIN") === 0, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                "so_reuseport" => canReusePort(),
                "ipv6_v6only" => true,
            ],
        ]);

        // Do NOT use STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
        // Error reporting suppressed as error is immediately checked and reported with an exception.
        if (!$socket = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context)) {
            throw new \RuntimeException(\sprintf("Failed binding socket on %s: [Err# %s] %s", $uri, $errno, $errstr));
        }

        return $this->sockets[$uri] = $socket;
    }

    /**
     * @noinspection PhpUnusedPrivateMethodInspection
     *
     * @param string $event
     * @param mixed $data
     */
    private function handleMessage(string $event, $data): void
    {
        foreach ($this->onMessage[$event] ?? [] as $callback) {
            defer($callback, $data);
        }
    }
}
