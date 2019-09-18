<?php

namespace Amp\Cluster;

use Amp\Deferred;
use Amp\MultiReasonException;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\Parallel;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync\ChannelException;
use Amp\Promise;
use Amp\Success;
use Monolog\Logger;
use function Amp\asyncCall;
use function Amp\call;

final class Watcher
{
    const WORKER_TIMEOUT = 5000;
    const KEY_LENGTH = 32;
    const EMPTY_URI = '~';

    /** @var resource[] */
    private $sockets = [];

    /** @var bool */
    private $running = false;

    /** @var string[] */
    private $script;

    /** @var Logger */
    private $logger;

    /** @var int */
    private $nextId = 1;

    /** @var Internal\WorkerHub */
    private $hub;

    /** @var callable */
    private $bind;

    /** @var callable */
    private $notify;

    /** @var \SplObjectStorage */
    private $workers;

    /** @var callable[][] */
    private $onMessage = [];

    /** @var Deferred|null */
    private $deferred;

    /**
     * @param string|string[]  $script Script path and optional arguments.
     * @param Logger $logger
     */
    public function __construct($script, Logger $logger)
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

        $this->bind = \Closure::fromCallable([$this, 'bindSocket']);
        $this->notify = \Closure::fromCallable([$this, 'onReceivedMessage']);
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
     *
     * @return Promise Resolved when the cluster has started.
     */
    public function start(int $count): Promise
    {
        if ($this->running || $this->deferred) {
            throw new \Error("The cluster is already running or has already run");
        }

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->deferred = new Deferred;
        $this->running = true;

        return call(function () use ($count): \Generator {
            try {
                $promises = [];
                for ($i = 0; $i < $count; ++$i) {
                    $promises[] = $this->startWorker();
                }
                yield Promise\all($promises);
            } catch (\Throwable $exception) {
                $this->stop();
                throw $exception;
            }
        });
    }

    private function startWorker(): Promise
    {
        return call(function (): \Generator {
            if (Parallel::isSupported()) {
                $context = new Parallel($this->script);
            } else {
                $context = new Process($this->script);
            }

            yield $context->start();

            $id = $this->nextId++;

            if ($this->hub !== null) {
                $key = $this->hub->generateKey($id, self::KEY_LENGTH);

                yield $context->send($key);

                try {
                    $socket = yield $this->hub->accept($id);
                } catch (\Throwable $exception) {
                    if ($context->isRunning()) {
                        $context->kill();
                    }

                    throw new ClusterException("Starting the cluster worker failed", 0, $exception);
                }
            }

            $this->logger->info(\sprintf('Started worker with ID %d', $id));

            $worker = new Internal\IpcParent($context, $this->logger, $this->bind, $this->notify, $socket ?? null);

            if ($context instanceof Process) {
                $stdout = call(function () use ($context): \Generator {
                    $stream = $context->getStdout();
                    $stream->unreference();
                    while (null !== $chunk = yield $stream->read()) {
                        $this->logger->info(\sprintf('STDOUT from PID %d: %s', $context->getPid(), $chunk));
                    }
                });

                $stderr = call(function () use ($context): \Generator {
                    $stream = $context->getStderr();
                    $stream->unreference();
                    while (null !== $chunk = yield $stream->read()) {
                        $this->logger->error(\sprintf('STDERR from PID %d: %s', $context->getPid(), $chunk));
                    }
                });

                $promise = Promise\all([$stdout, $stderr]);
            } else {
                $promise = new Success;
            }

            $runner = $worker->run();

            $promise = call(function () use ($worker, $context, $id, $runner, $promise) {
                try {
                    try {
                        yield $runner; // Wait for worker to exit.
                        $this->logger->info("Worker {$id} terminated cleanly" .
                            ($this->running ? ", restarting..." : "."));
                    } catch (ChannelException $exception) {
                        $this->logger->error("Worker {$id} died unexpectedly" .
                            ($this->running ? ", restarting..." : "."));
                    } catch (ContextException $exception) {
                        $this->logger->error("Worker {$id} died unexpectedly" .
                            ($this->running ? ", restarting..." : "."));
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
                        yield $this->startWorker();
                    }

                    // Wait for the STDIO streams to be consumed and closed.
                    yield $promise;
                } catch (\Throwable $exception) {
                    $this->stop();
                    throw $exception;
                }
            });

            if (!$this->running) {
                // Cluster stopped while worker was starting, so immediately shutdown the worker.
                yield $worker->shutdown();
                return;
            }

            $this->workers->attach($worker, [$context, $promise]);
        });
    }

    /**
     * Returns a promise that is succeeds when the cluster has stopped or fails if a worker cannot be restarted or
     * if stopping the cluster fails.
     *
     * @return Promise
     */
    public function join(): Promise
    {
        if (!$this->deferred) {
            throw new \Error("The cluster has not been started");
        }

        return $this->deferred->promise();
    }

    /**
     * @return Promise
     */
    public function restart(): Promise
    {
        return call(function (): \Generator {
            $promises = [];
            foreach (clone $this->workers as $worker) {
                \assert($worker instanceof Internal\IpcParent);
                [$context, $promise] = $this->workers[$worker];
                \assert($context instanceof Context);

                $promises[] = call(function () use ($worker, $context, $promise): \Generator {
                    try {
                        yield $worker->shutdown();
                        yield Promise\timeout($promise, self::WORKER_TIMEOUT);
                    } finally {
                        if ($context->isRunning()) {
                            $context->kill();
                        }
                    }
                });
            }

            try {
                yield Promise\all($promises);
            } catch (\Throwable $exception) {
                $this->stop();
                throw $exception;
            }
        });
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

        $promise = call(function (): \Generator {
            $promises = [];
            foreach (clone $this->workers as $worker) {
                \assert($worker instanceof Internal\IpcParent);
                $promises[] = call(function () use ($worker): \Generator {
                    [$context, $promise] = $this->workers[$worker];
                    \assert($context instanceof Context);

                    try {
                        yield $worker->shutdown();
                        yield Promise\timeout($promise, self::WORKER_TIMEOUT);
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

            [$exceptions] = yield Promise\any($promises);

            $this->workers = new \SplObjectStorage;

            if ($count = \count($exceptions)) {
                if ($count === 1) {
                    $exception = $exceptions[0];
                    throw new ClusterException("Stopping the cluster failed: " . $exception->getMessage(), 0, $exception);
                }

                $exception = new MultiReasonException($exceptions);
                $message = \implode('; ', \array_map(function (\Throwable $exception): string {
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
    public function broadcast($data): Promise
    {
        $promises = [];
        /** @var Internal\IpcParent $worker */
        foreach ($this->workers as $worker) {
            $promises[] = $worker->send($data);
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
    private function onReceivedMessage(string $event, $data): void
    {
        foreach ($this->onMessage[$event] ?? [] as $callback) {
            asyncCall($callback, $data);
        }
    }
}
