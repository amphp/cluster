<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Closable;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * @template-covariant T
 */
final class StreamResourceReceivePipe implements Closable
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly Internal\TransferSocket $transferSocket;

    /** @var Suspension<(\Closure():never)|null>|null */
    private ?Suspension $waiting = null;

    /** @var \SplQueue<TransferredResource<string>> */
    private readonly \SplQueue $receiveQueue;

    public function __construct(
        ResourceStream $resourceStream,
        private readonly Serializer $serializer,
    ) {
        $this->transferSocket = $transferSocket = new Internal\TransferSocket($resourceStream);
        $this->receiveQueue = $receiveQueue = new \SplQueue();

        $streamResource = $resourceStream->getResource();
        if (!\is_resource($streamResource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $suspension = &$this->waiting;
        $onReadable = EventLoop::onReadable(
            $streamResource,
            static function (string $callbackId, $stream) use (
                &$suspension,
                $transferSocket,
                $receiveQueue,
            ): void {
                try {
                    if (\feof($stream)) {
                        $transferSocket->close();
                        $suspension?->resume(static fn () => throw new SocketException(
                            'The transfer socket closed while waiting to receive a socket',
                        ));
                    } else {
                        $received = $transferSocket->receiveSocket();
                        if (!$received) {
                            return;
                        }

                        $receiveQueue->push($received);
                        $suspension?->resume();
                    }
                } catch (\Throwable $exception) {
                    $transferSocket->close();
                    $suspension?->resume(static fn () => throw new SocketException(
                        'The transfer socket threw an exception: ' . $exception->getMessage(),
                        previous: $exception,
                    ));
                }

                $suspension = null;
            },
        );

        $this->transferSocket->onClose(static function () use (&$suspension, $onReadable): void {
            EventLoop::cancel($onReadable);
            $suspension?->resume(static fn () => throw new SocketException('The transfer socket closed unexpectedly'));
        });
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->transferSocket->close();
    }

    public function isClosed(): bool
    {
        return $this->transferSocket->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->transferSocket->onClose($onClose);
    }

    /**
     * @return TransferredResource<T>|null Object containing the received stream-socket resource and the data sent
     * or null if the transfer pipe is closed.
     *
     * @throws SocketException
     * @throws SerializationException
     */
    public function receive(?Cancellation $cancellation = null): ?TransferredResource
    {
        if ($this->waiting !== null) {
            throw new PendingReadError();
        }

        if ($this->transferSocket->isClosed()) {
            throw new SocketException('The transfer socket has been closed');
        }

        if ($this->receiveQueue->isEmpty()) {
            $this->waiting = $suspension = EventLoop::getSuspension();

            $waiting = &$this->waiting;
            $id = $cancellation?->subscribe(static function (CancelledException $exception) use (&$waiting): void {
                $waiting?->throw($exception);
                $waiting = null;
            });

            try {
                if ($closure = $suspension->suspend()) {
                    $closure();
                    throw new \Error('Closure was expected to throw an exception');
                }
            } finally {
                /** @psalm-suppress PossiblyNullArgument If $cancellation is not null, $id will not be null. */
                $cancellation?->unsubscribe($id);
            }
        }

        \assert(!$this->receiveQueue->isEmpty(), 'Queue of received sockets was empty after suspending!');

        /** @var TransferredResource<string> $transferred */
        $transferred = $this->receiveQueue->shift();

        return new TransferredResource(
            $transferred->getResource(),
            $this->serializer->unserialize($transferred->getData()),
        );
    }
}
