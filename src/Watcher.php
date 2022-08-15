<?php

namespace Amp\Cluster;

use Amp\ByteStream\ReadableResourceStream;
use Amp\CompositeException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Sync\ChannelException;
use Amp\TimeoutCancellation;
use Monolog\Logger;
use function Amp\async;

final class Watcher
{
    public const WORKER_TIMEOUT = 5;

    private readonly ClusterSocketServerProvider $provider;

    private bool $running = false;

    /** @var list<string> */
    private array $script;

    private int $nextId = 1;

    private readonly \SplObjectStorage $workers;

    /** @var list<\Closure(mixed):void> */
    private array $onMessage = [];

    private ?DeferredFuture $deferred = null;

    private ?DeferredCancellation $deferredCancellation = null;

    /**
     * @param string|string[] $script Script path and optional arguments.
     * @param Logger $logger
     */
    public function __construct(
        string|array $script,
        private readonly IpcHub $hub,
        private readonly Logger $logger,
    ) {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php'],
            \is_array($script) ? \array_values(\array_map(\strval(...), $script)) : [$script]
        );

        $this->workers = new \SplObjectStorage();
        $this->provider = new ClusterSocketServerProvider();
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Attaches a callback to be invoked when a message is received from a worker process.
     *
     * @param \Closure(mixed):void $callback
     */
    public function onMessage(\Closure $callback): void
    {
        $this->onMessage[] = $callback;
    }

    /**
     * @param int $count Number of cluster workers to spawn.
     */
    public function start(int $count): void
    {
        if ($this->running || $this->deferred) {
            throw new \Error("The cluster is already running or has already run");
        }

        $this->deferredCancellation = new DeferredCancellation();

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->deferred = new DeferredFuture();
        $this->running = true;

        try {
            $futures = [];
            for ($i = 0; $i < $count; ++$i) {
                $futures[] = $this->startWorker();
            }
            Future\await($futures);
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    private function startWorker(): Future
    {
        return async(function (): void {
            $context = ProcessContext::start($this->script);

            $id = $this->nextId++;

            $key = $this->hub->generateKey();

            $context->send([
                'uri' => $this->hub->getUri(),
                'key' => $key,
            ]);

            try {
                $socket = $this->hub->accept($key);
            } catch (\Throwable $exception) {
                if (!$context->isClosed()) {
                    $context->close();
                }

                throw new ClusterException("Starting the cluster worker failed", 0, $exception);
            }

            $worker = new Internal\Worker(
                $id,
                $context,
                $socket,
                $this->handleMessage(...),
                $this->logger,
            );

            $worker->info(\sprintf('Started worker with ID %d', $id));

            $future = async(function () use ($worker, $context, $socket, $id): void {
                $pid = $context->getPid();

                $provider = async(fn () => Future\await([
                    self::pipeOutputToLogger('STDOUT', $pid, $context->getStdout(), $this->logger),
                    self::pipeOutputToLogger('STDERR', $pid, $context->getStderr(), $this->logger),
                    $this->provider->provideFor($socket),
                ]));

                try {
                    try {
                        $worker->run($this->deferredCancellation->getCancellation());

                        $worker->info("Worker {$id} terminated cleanly" .
                            ($this->running ? ", restarting..." : ""));
                    } catch (ChannelException $exception) {
                        $worker->error("Worker {$id} (PID {$pid}) died unexpectedly: {$exception->getMessage()}" .
                            ($this->running ? ", restarting..." : ""));
                    } catch (\Throwable $exception) {
                        $worker->error(
                            "Worker {$id} (PID {$pid}) failed: " . (string)$exception,
                            ['exception' => $exception],
                        );
                        throw $exception;
                    } finally {
                        $context->close();
                    }

                    // Wait for the STDIO streams to be consumed and closed.
                    $provider->await();

                    if ($this->running) {
                        $this->startWorker()->await();
                    }
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

            $this->workers->attach($worker, [$context, $future]);
        });
    }

    private static function pipeOutputToLogger(
        string $pipe,
        int $pid,
        ReadableResourceStream $stream,
        Logger $logger,
    ): Future {
        $stream->unreference();
        return async(static function () use ($pipe, $pid, $stream, $logger): void {
            while (null !== $chunk = $stream->read()) {
                $logger->info(\sprintf('%s from PID %d: %s', $pipe, $pid, $chunk), ['pipe' => $pipe, 'pid' => $pid]);
            }
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

        $this->deferred->getFuture()->await();
    }

    public function restart(): void
    {
        $futures = [];
        foreach (clone $this->workers as $worker) {
            \assert($worker instanceof Internal\Worker);
            [$context, $future] = $this->workers[$worker];
            \assert($context instanceof Context && $future instanceof Future);

            $futures[] = async(function () use ($worker, $context, $future): void {
                try {
                    $worker->shutdown();
                    $future->await(new TimeoutCancellation(self::WORKER_TIMEOUT));
                } finally {
                    if (!$context->isClosed()) {
                        $context->close();
                    }
                }
            });
        }

        try {
            Future\await($futures);
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
        $this->deferredCancellation->cancel();

        $futures = [];
        foreach (clone $this->workers as $worker) {
            \assert($worker instanceof Internal\Worker);
            $futures[] = async(function () use ($worker): void {
                [$context, $future] = $this->workers[$worker];
                \assert($context instanceof Context && $future instanceof Future);

                try {
                    $worker->shutdown();
                    $future->await(new TimeoutCancellation(self::WORKER_TIMEOUT));
                } catch (ContextException) {
                    // Ignore if the worker has already died unexpectedly.
                } finally {
                    if (!$context->isClosed()) {
                        $context->close();
                    }

                    $this->workers->detach($worker);
                }
            });
        }

        [$exceptions] = Future\awaitAll($futures);

        $count = \count($exceptions);
        if (!$count) {
            $this->deferred->complete();
            return;
        }

        if ($count === 1) {
            $exception = \current($exceptions);
            $this->deferred->error(new ClusterException(
                "Stopping the cluster failed: " . $exception->getMessage(),
                0,
                $exception,
            ));
            return;
        }

        $exception = new CompositeException($exceptions);
        $message = \implode('; ', \array_map(static function (\Throwable $exception): string {
            return $exception->getMessage();
        }, $exceptions));
        $this->deferred->error(new ClusterException("Stopping the cluster failed: " . $message, 0, $exception));
    }

    /**
     * Broadcast data to all workers, triggering any callbacks registered with Cluster::onMessage().
     */
    public function broadcast(mixed $data): void
    {
        /** @var Internal\Worker $worker */
        foreach ($this->workers as $worker) {
            $worker->send($data);
        }
    }

    private function handleMessage(mixed $data): void
    {
        foreach ($this->onMessage as $callback) {
            async($callback, $data);
        }
    }
}
