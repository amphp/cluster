<?php

namespace Amp\Cluster\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\Watcher;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Parallel\Context\ProcessContext;
use Amp\Socket\Socket;
use Amp\Sync\ChannelException;
use Amp\TimeoutCancellation;
use Monolog\Handler\HandlerInterface as MonologHandler;
use Monolog\Logger;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;
use function Amp\weakClosure;

/** @internal */
final class Worker extends AbstractLogger implements \Amp\Cluster\Worker
{
    private const PING_TIMEOUT = 10;

    private int $lastActivity;

    private ?DeferredCancellation $deferredCancellation = null;

    public function __construct(
        private readonly int $id,
        private readonly ProcessContext $context,
        private readonly Socket $socket,
        private readonly \Closure $onData,
        private readonly Logger $logger,
    ) {
        $this->lastActivity = \time();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function send(mixed $data): void
    {
        $this->context->send(new ClusterMessage(ClusterMessageType::Data, $data));
    }

    public function run(): void
    {
        $this->deferredCancellation = new DeferredCancellation;

        $watcher = EventLoop::repeat(self::PING_TIMEOUT / 4, weakClosure(function (): void {
            if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                $this->shutdown();
                return;
            }

            try {
                $this->context->send(new ClusterMessage(ClusterMessageType::Ping, 0));
            } catch (\Throwable) {
                $this->shutdown();
            }
        }));

        try {
            // We get null as last message from the cluster-runner in case it's shutting down cleanly. In that case, join it.
            while ($message = $this->context->receive($this->deferredCancellation->getCancellation())) {
                $this->lastActivity = \time();

                match ($message->type) {
                    ClusterMessageType::Pong => null,

                    ClusterMessageType::Data => ($this->onData)($message->data),

                    ClusterMessageType::Log => \array_map(
                        static fn(MonologHandler $handler) => $handler->handle($message->data),
                        $this->logger->getHandlers(),
                    ),

                    ClusterMessageType::Ping => throw new \RuntimeException(),
                };
            }

            $this->context->join(new CompositeCancellation($this->deferredCancellation->getCancellation(), new TimeoutCancellation(Watcher::WORKER_TIMEOUT)));
        } finally {
            EventLoop::cancel($watcher);
            $this->shutdown();
        }
    }

    public function shutdown(?Cancellation $cancellation = null): void
    {
        try {
            if ($cancellation) {
                try {
                    $this->context->send(null);
                } catch (ChannelException) {
                    // Ignore if the worker has already exited
                }
                try {
                    $future = new DeferredFuture;
                    $this->deferredCancellation->getCancellation()->subscribe($future->complete(...));
                    $future->getFuture()->await($cancellation);
                } catch (CancelledException) {
                    // Worker did not die normally within cancellation window
                }
            }
        } finally {
            $this->socket->close();

            $this->context->close();

            $this->context->getStdout()->close();
            $this->context->getStderr()->close();

            $this->deferredCancellation->cancel();
        }
    }

    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, $message, \array_merge(
            $context,
            ['id' => $this->id, 'pid' => $this->context->getPid()],
        ));
    }
}
