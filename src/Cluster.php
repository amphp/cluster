<?php

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\Cluster\Internal\ClusterMessage;
use Amp\Cluster\Internal\ClusterLogHandler;
use Amp\Cluster\Internal\ClusterMessageType;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\SignalCancellation;
use Amp\Socket\ResourceSocketServerFactory;
use Amp\Socket\Socket;
use Amp\Socket\SocketServerFactory;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;
use function Amp\trapSignal;

final class Cluster implements Channel
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
        if (!self::$cluster) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster. " .
                "Use " . __CLASS__ . "::isWorker() to determine if the process is running as a worker.");
        }

        return self::$cluster;
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
        if (!self::$cluster) {
            throw new \Error(__FUNCTION__ . " should only be called when running as a worker. " .
                "Create your own log handler when not running as part of a cluster. " .
                "Use " . __CLASS__ . "::isWorker() to determine if the process is running as a worker.");
        }

        return new ClusterLogHandler(self::$cluster->ipcChannel, $logLevel, $bubble);
    }

    /**
     * @return list<int>
     */
    public static function getSignalList(): array
    {
        return [
            \defined('SIGINT') ? \SIGINT : 2,
            \defined('SIGQUIT') ? \SIGQUIT : 3,
            \defined('SIGTERM') ? \SIGTERM : 15,
        ];
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

    private readonly Queue $queue;
    private readonly ConcurrentIterator $iterator;

    /**
     * @param Channel<ClusterMessage, ClusterMessage> $ipcChannel
     * @param ClusterSocketServerFactory $socketServerFactory
     */
    private function __construct(
        private readonly Channel $ipcChannel,
        private readonly ClusterSocketServerFactory $socketServerFactory,
    ) {
        $this->queue = new Queue();
        $this->iterator = $this->queue->iterate();
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if (!$this->iterator->continue($cancellation)) {
            $this->close();
            throw new ChannelException('Cluster channel closed unexpectedly while waiting to receive data');
        }

        return $this->iterator->getValue();
    }

    public function send(mixed $data): void
    {
        $this->ipcChannel->send(new ClusterMessage(ClusterMessageType::Data, $data));
    }

    public function close(): void
    {
        $this->ipcChannel->close();
    }

    public function isClosed(): bool
    {
        return $this->ipcChannel->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->ipcChannel->onClose($onClose);
    }

    private function loop(Cancellation $cancellation): void
    {
        try {
            while ($message = $this->ipcChannel->receive($cancellation)) {
                if (!$message instanceof ClusterMessage) {
                    throw new \ValueError(
                        'Unexpected message type received on internal channel: ' . \get_debug_type($message),
                    );
                }

                match ($message->type) {
                    ClusterMessageType::Ping => $this->ipcChannel->send(
                        new ClusterMessage(ClusterMessageType::Pong, $message->data),
                    ),

                    ClusterMessageType::Data => $this->queue->push($message->data),

                    ClusterMessageType::Pong,
                    ClusterMessageType::Log => throw new \RuntimeException(),
                };
            }
        } catch (ChannelException) {
            // IPC Channel manually closed
        } finally {
            $this->queue->complete();
        }
    }
}
