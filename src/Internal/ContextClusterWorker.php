<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\ClusterWatcher;
use Amp\Cluster\ClusterWorker;
use Amp\Cluster\ClusterWorkerMessage;
use Amp\DeferredCancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ProcessContext;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\Sync\ChannelException;
use Amp\TimeoutCancellation;
use Monolog\Handler\HandlerInterface as MonologHandler;
use Monolog\Logger;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;
use function Amp\async;
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

    private const PING_TIMEOUT = 10;

    private int $lastActivity;

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
        $this->lastActivity = \time();
        $this->joinFuture = async($this->context->join(...));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function send(mixed $data): void
    {
        $this->context->send(new WatcherMessage(WatcherMessageType::Data, $data));
    }

    public function run(): void
    {
        $watcher = EventLoop::repeat(self::PING_TIMEOUT / 2, weakClosure(function (): void {
            if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                $this->close();
                return;
            }

            try {
                $this->context->send(new WatcherMessage(WatcherMessageType::Ping, 0));
            } catch (\Throwable) {
                $this->close();
            }
        }));

        $cancellation = $this->deferredCancellation->getCancellation();

        try {
            // We get null as last message from the cluster-runner in case it's shutting down cleanly.
            // In that case, join it.
            /** @var WorkerMessage $message */
            while ($message = $this->context->receive($cancellation)) {
                $this->lastActivity = \time();

                /** @psalm-suppress UnhandledMatchCondition False positive. */
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

            $this->joinFuture->await(new TimeoutCancellation(ClusterWatcher::WORKER_TIMEOUT));
        } catch (\Throwable $exception) {
            $this->joinFuture->ignore();
            throw $exception;
        } finally {
            EventLoop::cancel($watcher);
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

    public function log($level, $message, array $context = []): void
    {
        $context['id'] = $this->id;
        if ($this->context instanceof ProcessContext) {
            $context['pid'] = $this->context->getPid();
        }

        $this->logger->log($level, $message, $context);
    }
}
