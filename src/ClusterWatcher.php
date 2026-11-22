<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\Internal\ContextClusterWorker;
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
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use Amp\Sync\ChannelException;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;
use function Amp\now;

/**
 * @template-covariant TReceive
 * @template TSend
 */
final class ClusterWatcher
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * The default worker shutdown timeout in seconds.
     *
     * @deprecated Use {@see self::DEFAULT_WORKER_SHUTDOWN_TIMEOUT} instead.
     */
    public const WORKER_TIMEOUT = self::DEFAULT_WORKER_SHUTDOWN_TIMEOUT;

    /**
     * The default worker shutdown timeout in seconds.
     */
    public const DEFAULT_WORKER_SHUTDOWN_TIMEOUT = 5;

    /**
     * The default worker ping timeout in seconds.
     */
    public const DEFAULT_WORKER_PING_TIMEOUT = 10;

    /**
     * A worker exiting on its own within this many seconds of starting is considered to have failed. Once a worker
     * run time has exceeded this time, future restarts are not delayed.
     */
    private const RESTART_STABLE_TIME = 30;

    /** Delay in seconds before restarting after the first failure, doubling with each consecutive failure. */
    private const RESTART_DELAY_INITIAL = 1;

    /** Maximum delay in seconds before restarting a failing worker. */
    private const RESTART_DELAY_MAX = 30;

    private readonly ContextFactory $contextFactory;

    private readonly Logger $logger;

    private bool $running = false;

    /** @var non-empty-list<string> */
    private readonly array $script;

    /** @var positive-int */
    private int $nextId = 1;

    /** @var array<int, Internal\ContextClusterWorker<TReceive, TSend>> */
    private array $workers = [];

    /** @var array<int, Future<void>> */
    private array $workerFutures = [];

    /** @var Queue<ClusterWorkerMessage<TReceive, TSend>> */
    private readonly Queue $queue;

    /** @var ConcurrentIterator<ClusterWorkerMessage<TReceive, TSend>> */
    private readonly ConcurrentIterator $iterator;

    /** Cancelled when the watcher is stopped to abort delayed worker restarts. */
    private readonly DeferredCancellation $stopCancellation;

    /**
     * @param string|array<string> $script Script path and optional arguments.
     * @param IpcHub $hub Sockets returned from {@see IpcHub::accept()} must be an instance of {@see ResourceSocket}.
     * @param float|null $workerShutdownTimeout The maximum time to wait for a worker to shut down, in seconds,
     *       or null to wait indefinitely.
     * @param float $workerPingTimeout Seconds without activity before the watcher considers a worker dead
     *       and terminates it. Default {@see self::DEFAULT_WORKER_PING_TIMEOUT}. Increase or applications that
     *       legitimately do synchronous blocking work (e.g. PDO drivers) longer than the default ceiling.
     */
    public function __construct(
        string|array $script,
        PsrLogger $logger,
        private readonly IpcHub $hub = new LocalIpcHub(),
        ?ContextFactory $contextFactory = null,
        private readonly ServerSocketPipeProvider $provider = new ServerSocketPipeProvider(),
        private readonly ?float $workerShutdownTimeout = self::DEFAULT_WORKER_SHUTDOWN_TIMEOUT,
        private readonly float $workerPingTimeout = self::DEFAULT_WORKER_PING_TIMEOUT,
    ) {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        if ($this->workerShutdownTimeout !== null && $this->workerShutdownTimeout <= 0) {
            throw new \ValueError(\sprintf(
                'Worker shutdown timeout must be NULL or greater than zero (seconds); got %.3f',
                $this->workerShutdownTimeout,
            ));
        }

        if ($this->workerPingTimeout <= 0) {
            throw new \ValueError(\sprintf(
                'Worker ping timeout must be greater than zero (seconds); got %.3f',
                $this->workerPingTimeout,
            ));
        }

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php'],
            \is_array($script) ? \array_values(\array_map(\strval(...), $script)) : [$script],
        );

        $this->contextFactory = $contextFactory ?? new DefaultContextFactory(ipcHub: $this->hub);
        $this->logger = $this->createLogger($logger);

        $this->queue = new Queue();
        $this->iterator = $this->queue->iterate();
        $this->stopCancellation = new DeferredCancellation();
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
     * Returns a concurrent iterator of messages sent from workers using {@see Cluster::getChannel()::send()}.
     * The returned iterator is completed when the cluster watcher is stopped.
     *
     * @return ConcurrentIterator<ClusterWorkerMessage<TReceive, TSend>>
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
            throw new \Error("The cluster watcher is already running or has already run");
        }

        if ($count <= 0) {
            throw new \ValueError("The number of workers must be greater than zero");
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
     * @param positive-int $id The worker ID.
     * @param int<0, max> $failures Number of consecutive workers which failed before this one, used to delay
     *     restarting this worker if it also fails.
     */
    private function startWorker(int $id, int $failures = 0): ContextClusterWorker
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

        if (!$socket instanceof ResourceSocket) {
            throw new \TypeError(\sprintf(
                "The %s instance returned from %s::accept() must also implement %s",
                Socket::class,
                \get_class($this->hub),
                ResourceStream::class,
            ));
        }

        $deferredCancellation = new DeferredCancellation();
        $worker = new Internal\ContextClusterWorker(
            $id,
            $context,
            $socket,
            $this->queue,
            $deferredCancellation,
            $this->logger,
        );

        $worker->info(\sprintf('Started cluster worker with ID %d', $id));

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
            $failures,
        ): void {
            $startedAt = now();

            async($this->provider->provideFor(...), $socket, $deferredCancellation->getCancellation())->ignore();

            try {
                try {
                    $worker->run($this->workerPingTimeout, $this->workerShutdownTimeout);

                    $worker->info(\sprintf('Worker %d terminated', $id));
                } catch (CancelledException) {
                    $worker->info(\sprintf('Worker %d forcefully terminated as part of watcher shutdown', $id));
                } catch (ChannelException $exception) {
                    $worker->error(
                        \sprintf('Worker %d died unexpectedly: %s', $id, $exception->getMessage()),
                        ['exception' => $exception],
                    );
                } catch (\Throwable $exception) {
                    $worker->error(\sprintf(
                        'Worker %d failed: %s: %s',
                        $id,
                        \get_class($exception),
                        $exception->getMessage(),
                    ), ['exception' => $exception]);
                } finally {
                    $deferredCancellation->cancel();
                    unset($this->workers[$id], $this->workerFutures[$id]);
                    $context->close();
                }

                if (!$this->running) {
                    return;
                }

                // A worker which exited on its own soon after starting is likely failing repeatedly, so delay
                // restarting it with an exponential backoff after each repeated failure.
                $lifetime = now() - $startedAt;
                $failures = $worker->isShutdownRequested() || $lifetime >= self::RESTART_STABLE_TIME
                    ? 0
                    : $failures + 1;

                if ($failures > 0) {
                    /** @psalm-suppress InvalidOperand Mixed int and float operands intentionally produce a float. */
                    $restartDelay = \min(
                        (int) (self::RESTART_DELAY_INITIAL * (2 ** ($failures - 1))),
                        self::RESTART_DELAY_MAX,
                    );

                    $worker->warning(\sprintf(
                        'Worker %d exited %.1f seconds after starting; delaying restart by %d seconds',
                        $id,
                        $lifetime,
                        $restartDelay,
                    ));

                    try {
                        delay($restartDelay, cancellation: $this->stopCancellation->getCancellation());
                    } catch (CancelledException) {
                        return; // Watcher stopped while waiting to restart.
                    }
                }

                $newId = $this->nextId++;
                $this->workers[$newId] = $this->startWorker($newId, $failures);

                $worker->info(\sprintf('Worker %d was restarted, replaced by worker %d', $id, $newId));
            } catch (\Throwable) {
                $this->stop();
            }
        })->ignore();

        return $worker;
    }

    /**
     * Restarts all workers simultaneously.
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
     * Stops all cluster workers. Workers are killed if the cancellation token is cancelled.
     *
     * @param Cancellation|null $cancellation Token to request cancellation of waiting for shutdown.
     * When cancelled, the workers are forcefully killed. If null, the workers are killed immediately.
     */
    public function stop(?Cancellation $cancellation = null): void
    {
        if (!$this->running || $this->queue->isComplete()) {
            return;
        }

        $this->running = false;
        $this->stopCancellation->cancel();

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
     * Broadcast data to all workers, received by {@see Cluster::getChannel()::receive()} in workers.
     *
     * @param TSend $data
     */
    public function broadcast(mixed $data): void
    {
        $futures = [];
        foreach ($this->workers as $worker) {
            $futures[] = async($worker->send(...), $data);
        }

        Future\await($futures);
    }
}
