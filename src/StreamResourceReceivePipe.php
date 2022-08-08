<?php

namespace Amp\Cluster;

use Amp\ByteStream\PendingReadError;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Closable;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class StreamResourceReceivePipe implements Closable
{
    /** @var Suspension<null|\Closure():never>|null */
    private ?Suspension $waiting = null;

    /** @var \SplQueue<array{resource, string}> */
    private readonly \SplQueue $receiveQueue;

    public function __construct(
        private readonly Socket $socket,
        private readonly Serializer $serializer,
    ) {
        $transferSocket = new Internal\TransferSocket($socket);
        $this->receiveQueue = $receiveQueue = new \SplQueue();

        $streamResource = $socket->getResource();
        if (!\is_resource($streamResource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $suspension = &$this->waiting;
        $onReadable = EventLoop::onReadable(
            $streamResource,
            static function (string $callbackId, $stream) use (
                &$suspension,
                $transferSocket,
                $socket,
                $receiveQueue,
            ): void {
                try {
                    if (\feof($stream)) {
                        $socket->close();
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
                    $socket->close();
                    $suspension?->resume(static fn () => throw new SocketException(
                        'The transfer socket threw an exception: ' . $exception->getMessage(),
                        0,
                        $exception,
                    ));
                }

                $suspension = null;
            },
        );

        $this->socket->onClose(static function () use (&$suspension, $onReadable): void {
            EventLoop::cancel($onReadable);
            $suspension?->throw(new SocketException('The transfer socket closed unexpectedly'));
        });
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    /**
     * @return array{resource, mixed}|null Tuple of the received stream-socket resource and the data sent or null
     *  if the transfer pipe is closed.
     *
     * @throws SocketException
     * @throws SerializationException
     */
    public function receive(?Cancellation $cancellation = null): ?array
    {
        if ($this->waiting !== null) {
            throw new PendingReadError;
        }

        if ($this->socket->isClosed()) {
            throw new SocketException('The transfer socket has been closed');
        }

        if ($this->receiveQueue->isEmpty()) {
            $this->waiting = EventLoop::getSuspension();

            $waiting = &$this->waiting;
            $id = $cancellation?->subscribe(static function (CancelledException $exception) use (&$waiting): void {
                $waiting?->throw($exception);
                $waiting = null;
            });

            try {
                if ($closure = $this->waiting->suspend()) {
                    $closure();
                }
            } finally {
                /** @psalm-suppress PossiblyNullArgument If $cancellation is not null, $id will not be null. */
                $cancellation?->unsubscribe($id);
            }
        }

        \assert(!$this->receiveQueue->isEmpty(), 'Queue of received sockets was empty after suspending!');

        [$import, $data] = $this->receiveQueue->shift();

        return [$import, $this->serializer->unserialize($data)];
    }
}
