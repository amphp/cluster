<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Cluster\Internal\ClusterLogHandler;
use Amp\Cluster\Internal\ClusterMessage;
use Amp\Cluster\Internal\ClusterMessageType;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\SignalCancellation;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\Socket;
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

    public static function getServerSocketFactory(): ServerSocketFactory
    {
        return self::$cluster?->serverSocketFactory ?? new ResourceServerSocketFactory();
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
            \defined('SIGHUP') ? \SIGHUP : 1,
            \defined('SIGINT') ? \SIGINT : 2,
            \defined('SIGQUIT') ? \SIGQUIT : 3,
            \defined('SIGTERM') ? \SIGTERM : 15,
        ];
    }

    public static function awaitTermination(): int
    {
        try {
            return trapSignal(self::getSignalList(), true, self::$cluster?->loopCancellation->getCancellation());
        } catch (CancelledException) {
            return 0;
        }
    }

    private static function init(Channel $channel, Socket $transferSocket): void
    {
        self::$cluster = new self($channel, new ClusterServerSocketFactory($transferSocket));
    }

    private static function run(): void
    {
        self::$cluster->loop(new SignalCancellation(self::getSignalList()));
    }

    public static function shutdown(): void
    {
        self::$cluster?->close();
    }

    private readonly Queue $queue;
    private readonly ConcurrentIterator $iterator;
    private DeferredCancellation $loopCancellation;

    /**
     * @param Channel<ClusterMessage, ClusterMessage> $ipcChannel
     */
    private function __construct(
        private readonly Channel                    $ipcChannel,
        private readonly ClusterServerSocketFactory $serverSocketFactory,
    ) {
        $this->queue = new Queue();
        $this->iterator = $this->queue->iterate();
    }

    public function receive(?Cancellation $cancellation = null): mixed
    {
        if (!$this->iterator->continue($cancellation)) {
            if ($this->loopCancellation->isCancelled()) {
                throw new ChannelException('Cluster channel manually closed while waiting to receive data');
            }
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
        // Don't close the ipcChannel directly here, that's the task of the process-runner
        $this->loopCancellation->cancel();
    }

    public function isClosed(): bool
    {
        return $this->loopCancellation->isCancelled();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->loopCancellation->getCancellation()->subscribe($onClose);
    }

    private function loop(Cancellation $cancellation): void
    {
        $this->loopCancellation = new DeferredCancellation;
        $abortCancellation = new CompositeCancellation($this->loopCancellation->getCancellation(), $cancellation);

        try {
            while ($message = $this->ipcChannel->receive($abortCancellation)) {
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
        } catch (CancelledException | ChannelException) {
            // IPC Channel manually closed
        } finally {
            $this->loopCancellation->cancel();
            $this->queue->complete();
        }
    }
}
