<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\Watcher;
use Amp\Cluster\Worker;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ProcessContext;
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
 * @template TReceive
 * @template TSend
 *
 * @implements Worker<TReceive, TSend>
 *
 * @internal
 */
final class ContextWorker extends AbstractLogger implements Worker
{
    private const PING_TIMEOUT = 10;

    private int $lastActivity;

    /** @var list<\Closure(TReceive):void> */
    private array $onMessage = [];

    private readonly Future $joinFuture;

    /**
     * @param Context<mixed, ClusterMessage|null, ClusterMessage|null> $context
     */
    public function __construct(
        private readonly int $id,
        private readonly Context $context,
        private readonly Socket $socket,
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

    public function onMessage(\Closure $onMessage): void
    {
        $this->onMessage[] = $onMessage;
    }

    public function send(mixed $data): void
    {
        $this->context->send(new ClusterMessage(ClusterMessageType::Data, $data));
    }

    public function run(): void
    {
        $watcher = EventLoop::repeat(self::PING_TIMEOUT / 2, weakClosure(function (): void {
            if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                $this->close();
                return;
            }

            try {
                $this->context->send(new ClusterMessage(ClusterMessageType::Ping, 0));
            } catch (\Throwable) {
                $this->close();
            }
        }));

        $cancellation = $this->deferredCancellation->getCancellation();

        try {
            // We get null as last message from the cluster-runner in case it's shutting down cleanly.
            // In that case, join it.
            /** @var ClusterMessage $message */
            while ($message = $this->context->receive($cancellation)) {
                $this->lastActivity = \time();

                /** @psalm-suppress UnhandledMatchCondition False positive. */
                match ($message->type) {
                    ClusterMessageType::Pong => null,

                    ClusterMessageType::Data => $this->handleMessage($message->data),

                    ClusterMessageType::Log => \array_map(
                        static fn (MonologHandler $handler) => $handler->handle($message->data),
                        $this->logger->getHandlers(),
                    ),

                    ClusterMessageType::Ping => throw new \RuntimeException('Unexpected message type received'),
                };
            }

            $this->joinFuture->await(new TimeoutCancellation(Watcher::WORKER_TIMEOUT));
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

    private function handleMessage(mixed $data): void
    {
        foreach ($this->onMessage as $onMessage) {
            async($onMessage, $data);
        }
    }
}
