<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\Cluster\Internal\ClusterLogHandler;
use Amp\Cluster\Internal\ClusterMessage;
use Amp\Cluster\Internal\ClusterMessageType;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ResourceSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Monolog\Handler\HandlerInterface as MonologHandler;
use Monolog\Level;
use Psr\Log\LogLevel;
use function Amp\trapSignal;

/**
 * @template-covariant TReceive
 * @template TSend
 * @implements Channel<TReceive, TSend>
 */
final class Cluster implements Channel
{
    use ForbidCloning;
    use ForbidSerialization;

    private static ?self $cluster = null;

    public static function isWorker(): bool
    {
        return self::$cluster !== null;
    }

    /**
     * @return int<0, max> Returns the context ID of the execution context or 0 if running as an independent script.
     */
    public static function getContextId(): int
    {
        return self::$cluster?->contextId ?? 0;
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
     * @param value-of<Level::NAMES>|value-of<Level::VALUES>|Level|LogLevel::* $logLevel Log level for the IPC handler
     * @param bool $bubble Bubble flag for the IPC handler
     *
     * @throws \Error Thrown if not running as a worker.
     *
     * @psalm-suppress MismatchingDocblockParamType, PossiblyInvalidArgument, UnresolvableConstant
     */
    public static function createLogHandler(
        int|string|Level $logLevel = LogLevel::DEBUG,
        bool $bubble = false,
    ): MonologHandler {
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

    public static function awaitTermination(?Cancellation $cancellation = null): void
    {
        if (!self::$cluster) {
            trapSignal(self::getSignalList(), cancellation: $cancellation);
            return;
        }

        $deferredFuture = new DeferredFuture();
        $loopCancellation = self::$cluster->loopCancellation->getCancellation();
        $loopId = $loopCancellation->subscribe($deferredFuture->complete(...));
        $cancellationId = $cancellation?->subscribe(static fn () => $loopCancellation->unsubscribe($loopId));

        try {
            $deferredFuture->getFuture()->await($cancellation);
        } finally {
            /** @psalm-suppress PossiblyNullArgument $cancellationId is not null if $cancellation is not null. */
            $cancellation?->unsubscribe($cancellationId);
        }
    }

    /**
     * @param positive-int $contextId
     */
    private static function run(int $contextId, Channel $channel, ResourceSocket $transferSocket): void
    {
        self::$cluster = new self($contextId, $channel, new ClusterServerSocketFactory($transferSocket));
        self::$cluster->loop();
    }

    public static function shutdown(): void
    {
        self::$cluster?->close();
    }

    /** @var Queue<TReceive> */
    private readonly Queue $queue;

    /** @var ConcurrentIterator<TReceive> */
    private readonly ConcurrentIterator $iterator;

    private readonly DeferredCancellation $loopCancellation;

    /**
     * @param int<0, max> $contextId
     * @param Channel<ClusterMessage, ClusterMessage> $ipcChannel
     */
    private function __construct(
        private readonly int $contextId,
        private readonly Channel $ipcChannel,
        private readonly ClusterServerSocketFactory $serverSocketFactory,
    ) {
        $this->queue = new Queue();
        $this->iterator = $this->queue->iterate();
        $this->loopCancellation = new DeferredCancellation();
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
        $this->loopCancellation->getCancellation()->subscribe(static fn () => $onClose());
    }

    private function loop(): void
    {
        $abortCancellation = $this->loopCancellation->getCancellation();

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

                    ClusterMessageType::Data => $this->queue->pushAsync($message->data)->ignore(),

                    ClusterMessageType::Pong,
                    ClusterMessageType::Log => throw new \RuntimeException('Unexpected message type received'),
                };
            }
        } catch (\Throwable) {
            // IPC Channel manually closed
        } finally {
            $this->loopCancellation->cancel();
            $this->queue->complete();
        }
    }
}
