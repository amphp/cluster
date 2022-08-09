<?php

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\Cluster\Internal\ClusterMessage;
use Amp\Cluster\Internal\ClusterLogHandler;
use Amp\Cluster\Internal\ClusterMessageType;
use Amp\Pipeline\Queue;
use Amp\SignalCancellation;
use Amp\Socket\ResourceSocketServerFactory;
use Amp\Socket\Socket;
use Amp\Socket\SocketServerFactory;
use Amp\Sync\Channel;
use Amp\Sync\Internal\ConcurrentIteratorChannel;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;
use function Amp\async;
use function Amp\trapSignal;

final class Cluster
{
    private static ?self $cluster = null;

    public static function isWorker(): bool
    {
        return self::$cluster !== null;
    }

    /**
     * @return int Returns the amphp context ID of the execution context.
     */
    public static function getContextId(): int
    {
        return \defined("AMP_CONTEXT_ID") ? \AMP_CONTEXT_ID : \getmypid();
    }

    public static function getSocketServerFactory(): SocketServerFactory
    {
        return self::$cluster?->socketServerFactory ?? new ResourceSocketServerFactory();
    }

    public static function getChannel(): Channel
    {
        if (!self::isWorker()) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster. " .
                "Use " . __CLASS__ . "::isWorker() to determine if the process is running as a worker.");
        }

        return self::$cluster->userChannel;
    }

    /**
     * Creates a log handler in worker processes that communicates log messages to the parent.
     *
     * @param string $logLevel Log level for the IPC handler
     * @param bool $bubble Bubble flag for the IPC handler
     *
     * @return HandlerInterface
     *
     * @throws \Error Thrown if not running as a worker.
     */
    public static function createLogHandler(string $logLevel = LogLevel::DEBUG, bool $bubble = false): HandlerInterface
    {
        if (!self::isWorker()) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster. " .
                "Use " . __CLASS__ . "::isWorker() to determine if the process is running as a worker.");
        }

        return new ClusterLogHandler(self::$cluster->internalChannel, $logLevel, $bubble);
    }

    public static function awaitTermination(): int
    {
        return trapSignal(self::getSignalList());
    }

    private static function run(Channel $channel, Socket $transferSocket): void
    {
        self::$cluster = new self($channel, new ClusterSocketServerFactory($transferSocket));
        self::$cluster->loop(new SignalCancellation(self::getSignalList()));
    }

    /**
     * @return list<int>
     */
    private static function getSignalList(): array
    {
        return [
            \defined('SIGINT') ? \SIGINT : 2,
            \defined('SIGQUIT') ? \SIGQUIT : 3,
            \defined('SIGTERM') ? \SIGTERM : 15,
            \defined('SIGSTOP') ? \SIGSTOP : 19,
        ];
    }

    private readonly Channel $userChannel;

    private readonly Queue $send;

    private readonly Queue $receive;

    /**
     * @param Channel<ClusterMessage, ClusterMessage> $internalChannel
     * @param ClusterSocketServerFactory $socketServerFactory
     */
    private function __construct(
        private readonly Channel $internalChannel,
        private readonly ClusterSocketServerFactory $socketServerFactory,
    ) {
        $this->receive = new Queue();
        $this->send = new Queue();

        $this->userChannel = new ConcurrentIteratorChannel($this->send->iterate(), $this->receive);
    }

    private function loop(Cancellation $cancellation): void
    {
        async(fn () => $this->receive->pipe()->forEach(
            fn (mixed $data) => $this->internalChannel->send(
                new ClusterMessage(ClusterMessageType::Data, $data),
            )
        ))->ignore();

        $id = $cancellation->subscribe(function (): void {
            $this->receive->complete();
            $this->send->complete();
        });

        try {
            while ($message = $this->internalChannel->receive($cancellation)) {
                if (!$message instanceof ClusterMessage) {
                    throw new \ValueError(
                        'Unexpected message type received on internal channel: ' . \get_debug_type($message),
                    );
                }

                match ($message->type) {
                    ClusterMessageType::Ping => $this->internalChannel->send(
                        new ClusterMessage(ClusterMessageType::Pong, $message->data),
                    ),

                    ClusterMessageType::Data => $this->send->push($message->data),

                    ClusterMessageType::Pong,
                    ClusterMessageType::Log => throw new \RuntimeException(),
                };
            }
        } finally {
            $cancellation->unsubscribe($id);
        }
    }
}
