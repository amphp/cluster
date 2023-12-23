<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\Internal\ContextWorker;
use Amp\CompositeException;
use Amp\DeferredCancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\Sync\ChannelException;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @template-covariant TReceive
 * @template TSend
 */
final class Watcher
{
    use ForbidCloning;
    use ForbidSerialization;

    public const WORKER_TIMEOUT = 5;

    private readonly ClusterServerSocketProvider $provider;

    private readonly ContextFactory $contextFactory;

    private readonly Logger $logger;

    private bool $running = false;

    /** @var non-empty-list<string> */
    private readonly array $script;

    /** @var positive-int */
    private int $nextId = 1;

    /** @var array<int, Internal\ContextWorker<TReceive, TSend>> */
    private array $workers = [];

    /** @var Future<void>[] */
    private array $workerFutures = [];

    /** @var Queue<WorkerMessage<TReceive, TSend>> */
    private Queue $queue;

    /** @var ConcurrentIterator<WorkerMessage<TReceive, TSend>> */
    private ConcurrentIterator $iterator;

    /**
     * @param string|array<string> $script Script path and optional arguments.
     */
    public function __construct(
        string|array $script,
        PsrLogger $logger,
        private readonly IpcHub $hub = new LocalIpcHub(),
    ) {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php'],
            \is_array($script) ? \array_values(\array_map(\strval(...), $script)) : [$script],
        );

        $this->contextFactory = new DefaultContextFactory(ipcHub: $this->hub);
        $this->provider = new ClusterServerSocketProvider();
        $this->logger = $this->createLogger($logger);

        $this->queue = new Queue();
        $this->iterator = $this->queue->iterate();
    }

    public function __destruct()
    {
        EventLoop::queue($this->stop(...));
    }

    private function createLogger(PsrLogger $psrLogger): Logger
    {
        if ($psrLogger instanceof Logger) {
            return $psrLogger;
        }

        $monologLogger = new Logger('cluster-watcher');
        $psrHandler = new PsrHandler($psrLogger);
        $monologLogger->pushHandler($psrHandler);

        return $monologLogger;
    }

    /**
     * @return ConcurrentIterator<WorkerMessage<TReceive, TSend>>
     */
    public function getMessageIterator(): ConcurrentIterator
    {
        return $this->iterator;
    }

    /**
     * @param int $count Number of cluster workers to spawn.
     */
    public function start(int $count): void
    {
        if ($this->running || $this->queue->isComplete()) {
            throw new \Error("The cluster is already running or has already run");
        }

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->workers = [];
        $this->running = true;

        try {
            for ($i = 0; $i < $count; ++$i) {
                $id = $this->nextId++;
                $this->workers[$id] = $this->startWorker($id);
            }
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    /**
     * @param positive-int $id
     */
    private function startWorker(int $id): ContextWorker
    {
        $context = $this->contextFactory->start($this->script);

        $key = $this->hub->generateKey();

        $context->send([
            'id' => $id,
            'uri' => $this->hub->getUri(),
            'key' => $key,
        ]);

        try {
            $socket = $this->hub->accept($key);
        } catch (\Throwable $exception) {
            if (!$context->isClosed()) {
                $context->close();
            }

            throw new ClusterException("Starting the cluster worker failed", previous: $exception);
        }

        if (!$socket instanceof ResourceStream) {
            throw new \TypeError(\sprintf(
                "The %s instance returned from %s must also implement %s",
                Socket::class,
                \get_class($this->hub),
                ResourceStream::class,
            ));
        }

        $deferredCancellation = new DeferredCancellation();
        $worker = new Internal\ContextWorker(
            $id,
            $context,
            $socket,
            $this->queue,
            $deferredCancellation,
            $this->logger,
        );

        $worker->info(\sprintf('Started worker with ID %d', $id));

        // Cluster stopped while worker was starting, so immediately throw everything away.
        if (!$this->running) {
            $worker->shutdown();
            return $worker;
        }

        $this->workerFutures[$id] = async(function () use (
            $worker,
            $context,
            $socket,
            $deferredCancellation,
            $id,
        ): void {
            $futures = [$this->provider->provideFor($socket)];

            try {
                try {
                    $worker->run();

                    $worker->info("Worker {$id} terminated cleanly" .
                        ($this->running ? ", restarting..." : ""));
                } catch (CancelledException) {
                    $worker->info("Worker {$id} forcefully terminated as part of watcher shutdown");
                } catch (ChannelException $exception) {
                    $worker->error("Worker {$id} died unexpectedly: {$exception->getMessage()}" .
                        ($this->running ? ", restarting..." : ""));
                } catch (\Throwable $exception) {
                    $worker->error(
                        "Worker {$id} failed: " . (string) $exception,
                        ['exception' => $exception],
                    );
                    throw $exception;
                } finally {
                    $deferredCancellation->cancel();
                    unset($this->workers[$id], $this->workerFutures[$id]);
                    $context->close();
                }

                // Wait for the STDIO streams to be consumed and closed.
                Future\await($futures);

                if ($this->running) {
                    $this->workers[$id] = $this->startWorker($this->nextId++);
                }
            } catch (\Throwable $exception) {
                $this->stop();
                throw $exception;
            }
        })->ignore();

        return $worker;
    }

    /**
     * Returns an array of all workers, mapped by their ID.
     *
     * @return array<int, Worker>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /**
     * Restarts all workers simultaneously without delay.
     */
    public function restart(): void
    {
        $futures = [];
        foreach ($this->workers as $worker) {
            $futures[] = async($worker->shutdown(...));
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
     *
     * @param Cancellation|null $cancellation Token to request cancellation of waiting for shutdown.
     * When cancelled, the workers are forcefully killed. If null, the workers are killed immediately.
     */
    public function stop(?Cancellation $cancellation = null): void
    {
        if ($this->queue->isComplete()) {
            return;
        }

        $this->running = false;

        $futures = [];
        foreach ($this->workers as $worker) {
            $futures[] = async(function () use ($worker, $cancellation): void {
                $future = $this->workerFutures[$worker->getId()] ?? null;

                try {
                    $worker->shutdown($cancellation);
                } catch (ContextException) {
                    // Ignore if the worker has already died unexpectedly.
                }

                // We need to await this future here, otherwise we may not log things properly if the
                // event-loop exits immediately after.
                $future?->await();
            });
        }

        [$exceptions] = Future\awaitAll($futures);

        try {
            if (!$exceptions) {
                $this->queue->complete();
                return;
            }

            if (\count($exceptions) === 1) {
                $exception = \array_shift($exceptions);
                $this->queue->error(new ClusterException(
                    "Stopping the cluster failed: " . $exception->getMessage(),
                    previous: $exception,
                ));
                return;
            }

            $exception = new CompositeException($exceptions);
            $message = \implode('; ', \array_map(static fn (\Throwable $e) => $e->getMessage(), $exceptions));
            $this->queue->error(new ClusterException("Stopping the cluster failed: " . $message, previous: $exception));
        } finally {
            $this->workers = [];
        }
    }

    /**
     * Broadcast data to all workers, sending data to active {@see Cluster::getChannel()::receive()} listeners.
     *
     * @param TSend $data
     */
    public function broadcast(mixed $data): void
    {
        foreach ($this->workers as $worker) {
            $worker->send($data);
        }
    }
}
