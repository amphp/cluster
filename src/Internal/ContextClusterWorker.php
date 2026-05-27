<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\ClusterWorker;
use Amp\Cluster\ClusterWorkerMessage;
use Amp\DeferredCancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Interval;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ProcessContext;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\Sync\ChannelException;
use Amp\TimeoutCancellation;
use Monolog\Handler\HandlerInterface as MonologHandler;
use Monolog\Logger;
use Psr\Log\AbstractLogger;
use function Amp\async;
use function Amp\now;
use function Amp\weakClosure;

/**
 * @template-covariant TReceive
 * @template TSend
 *
 * @implements ClusterWorker<TSend>
 *
 * @internal
 */
final class ContextClusterWorker extends AbstractLogger implements ClusterWorker
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var float Last time the worker sent a message. */
    private float $lastActivity;

    /** @var float Cached current time to avoid syscall on each message. */
    private float $now;

    private readonly Future $joinFuture;

    /**
     * @param positive-int $id
     * @param Context<mixed, WorkerMessage|null, WatcherMessage|null> $context
     * @param Queue<ClusterWorkerMessage<TReceive, TSend>> $queue
     */
    public function __construct(
        private readonly int $id,
        private readonly Context $context,
        private readonly Socket $socket,
        private readonly Queue $queue,
        private readonly DeferredCancellation $deferredCancellation,
        private readonly Logger $logger,
    ) {
        $this->lastActivity = $this->now = now();
        $this->joinFuture = async($this->context->join(...));
    }

    #[\Override]
    public function getId(): int
    {
        return $this->id;
    }

    #[\Override]
    public function send(mixed $data): void
    {
        $this->context->send(new WatcherMessage(WatcherMessageType::Data, $data));
    }

    /**
     * Run the worker.
     *
     * @param float $pingTimeout Seconds without activity before the watcher considers a worker dead
     *    or null to wait indefinitely.
     * @param float|null $shutdownTimeout The maximum time to wait for the worker to shut down, in seconds,
     *    and terminates it.
     */
    public function run(float $pingTimeout, ?float $shutdownTimeout): void
    {
        $interval = new Interval(1, weakClosure(function () use ($pingTimeout): void {
            $this->now = now();

            if ($this->lastActivity < $this->now - $pingTimeout) {
                $this->close();
                return;
            }

            if ($this->lastActivity >= $this->now - $pingTimeout / 2.0) {
                return;
            }

            try {
                $this->context->send(new WatcherMessage(WatcherMessageType::Ping, 0));
            } catch (\Throwable) {
                $this->close();
            }
        }), reference: false);

        $this->lastActivity = $this->now;

        $cancellation = $this->deferredCancellation->getCancellation();

        try {
            // We get null as last message from the cluster-runner in case it's shutting down cleanly.
            // In that case, join it.
            /** @var WorkerMessage $message */
            while ($message = $this->context->receive($cancellation)) {
                $this->lastActivity = $this->now;

                match ($message->type) {
                    WorkerMessageType::Pong => null,

                    WorkerMessageType::Data => $this->queue
                        ->pushAsync(new ClusterWorkerMessage($this, $message->data))
                        ->ignore(),

                    WorkerMessageType::Log => \array_map(
                        static fn (MonologHandler $handler) => $handler->handle($message->data),
                        $this->logger->getHandlers(),
                    ),
                };
            }

            try {
                if ($shutdownTimeout === null) {
                    $this->joinFuture->await();
                } else {
                    $this->joinFuture->await(new TimeoutCancellation($shutdownTimeout));
                }
            } catch (CancelledException) {
                $this->close();
                // Give it a second to reap the result. Generally this never should time out, unless something is
                // seriously broken.
                $this->joinFuture->await(new TimeoutCancellation(1));
            }
        } catch (\Throwable $exception) {
            $this->joinFuture->ignore();
            throw $exception;
        } finally {
            $interval->disable();
            $this->close();
        }
    }

    private function close(): void
    {
        $this->socket->close();
        $this->context->close();

        $this->deferredCancellation->cancel();
    }

    public function shutdown(?Cancellation $cancellation = null): void
    {
        try {
            if (!$this->context->isClosed()) {
                try {
                    $this->context->send(null);
                } catch (ChannelException) {
                    // Ignore if the worker has already exited
                }
            }

            try {
                $this->joinFuture->await($cancellation);
            } catch (CancelledException) {
                // Worker did not die normally within cancellation window
            }
        } finally {
            $this->close();
        }
    }

    /**
     * @psalm-suppress MissingParamType Type missing for compatibility with old versions of psr/log.
     */
    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        $context['id'] = $this->id;
        if ($this->context instanceof ProcessContext) {
            $context['pid'] = $this->context->getPid();
        }

        $this->logger->log($level, $message, $context);
    }
}
