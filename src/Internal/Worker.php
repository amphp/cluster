<?php

namespace Amp\Cluster\Internal;

use Amp\Cancellation;
use Amp\Cluster\Watcher;
use Amp\CompositeCancellation;
use Amp\Parallel\Context\ProcessContext;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use Monolog\Handler\HandlerInterface as MonologHandler;
use Monolog\Logger;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;
use function Amp\weakClosure;

/** @internal */
final class Worker extends AbstractLogger
{
    private const PING_TIMEOUT = 10;

    private int $lastActivity;

    public function __construct(
        private readonly int $id,
        private readonly ProcessContext $context,
        private readonly Socket $socket,
        private readonly \Closure $onData,
        private readonly Logger $logger,
    ) {
        $this->lastActivity = \time();
    }

    public function send(mixed $data): void
    {
        $this->context->send(new ClusterMessage(ClusterMessageType::Data, $data));
    }

    public function run(Cancellation $cancellation): void
    {
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
            while ($message = $this->context->receive($cancellation)) {
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

            $this->context->join(new CompositeCancellation($cancellation, new TimeoutCancellation(Watcher::WORKER_TIMEOUT)));
        } finally {
            EventLoop::cancel($watcher);
            $this->shutdown();
        }
    }

    public function shutdown(): void
    {
        $this->socket->close();

        $this->context->close();

        $this->context->getStdout()->close();
        $this->context->getStderr()->close();
    }

    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, $message, \array_merge(
            $context,
            ['id' => $this->id, 'pid' => $this->context->getPid()],
        ));
    }
}
