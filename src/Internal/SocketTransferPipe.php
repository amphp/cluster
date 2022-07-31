<?php

namespace Amp\Cluster\Internal;

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

final class SocketTransferPipe implements Closable
{
    private readonly TransferSocket $transferSocket;

    /** @var \SplQueue<array{Suspension<null|Closure():never>, resource, string}> */
    private readonly \SplQueue $transferQueue;

    private readonly string $onReadable;

    private readonly string $onWritable;

    /** @var Suspension<array{resource, string}|null>|null */
    private ?Suspension $waiting = null;

    public function __construct(
        private readonly Socket $socket,
        private readonly Serializer $serializer,
    ) {
        $this->transferSocket = $transferSocket = new TransferSocket($socket);
        $this->transferQueue = $transferQueue = new \SplQueue();

        $streamResource = $socket->getResource();
        if (!\is_resource($streamResource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $suspension = &$this->waiting;
        $this->onReadable = $onReadable = EventLoop::disable(EventLoop::onReadable(
            $streamResource,
            static function (string $callbackId, $stream) use (
                &$suspension,
                $transferSocket,
                $socket,
            ): void {
                EventLoop::disable($callbackId);

                \assert($suspension instanceof Suspension);

                try {
                    if (\feof($stream)) {
                        $suspension->resume();
                        $socket->close();
                        return;
                    }

                    $suspension->resume($transferSocket->receiveSocket());
                } catch (\Throwable $exception) {
                    $socket->close();
                    $suspension->throw($exception);
                } finally {
                    $suspension = null;
                }
            },
        ));

        $this->onWritable = $onWritable = EventLoop::disable(EventLoop::onWritable(
            $streamResource,
            static function (string $callbackId, $stream) use (
                $transferSocket,
                $socket,
                $transferQueue,
            ): void {
                if (\feof($stream)) {
                    $socket->close();
                    return;
                }

                while (!$transferQueue->isEmpty()) {
                    /**
                     * @var Suspension<null|Closure():never> $suspension
                     * @var resource $export
                     * @var string $data
                     */
                    [$suspension, $export, $data] = $transferQueue->dequeue();

                    try {
                        if (!$transferSocket->sendSocket($export, $data)) {
                            $transferQueue->enqueue([$suspension, $export, $data]);
                            return;
                        }
                    } catch (\Throwable $exception) {
                        $suspension->resume(static fn () => throw new SocketException(
                            'Failed to send socket: ' . $exception->getMessage(),
                            0,
                            $exception,
                        ));
                    }
                }

                EventLoop::disable($callbackId);
            },
        ));

        $this->socket->onClose(static function () use (&$suspension, $transferQueue, $onReadable, $onWritable): void {
            EventLoop::cancel($onReadable);
            EventLoop::cancel($onWritable);

            $suspension?->throw(new SocketException('The transfer socket closed unexpectedly'));

            while (!$transferQueue->isEmpty()) {
                /** @var Suspension<null|Closure():never> $pending */
                [$pending] = $transferQueue->dequeue();
                $pending->resume(
                    static fn () => throw new SocketException('The transfer socket closed unexpectedly')
                );
            }
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
     * @return array{resource, mixed}|null Array of the received stream-socket resource and the data sent or null
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

        $this->waiting = EventLoop::getSuspension();
        EventLoop::enable($this->onReadable);

        $id = $cancellation?->subscribe(function (CancelledException $exception): void {
            EventLoop::disable($this->onReadable);
            $this->waiting?->throw($exception);
            $this->waiting = null;
        });

        try {
            $received = $this->waiting->suspend();
        } finally {
            /** @psalm-suppress PossiblyNullArgument If $cancellation is not null, $id will not be null. */
            $cancellation?->unsubscribe($id);
        }

        if (!$received) {
            return null;
        }

        [$import, $data] = $received;

        return [$import, $this->serializer->unserialize($data)];
    }

    /**
     * @param resource $stream
     *
     * @throws SocketException
     * @throws SerializationException
     */
    public function send($stream, mixed $data = null): void
    {
        $serialized = $this->serializer->serialize($data);

        if (!$this->transferQueue->isEmpty() || !$this->transferSocket->sendSocket($stream, $serialized)) {
            /** @var Suspension<null|Closure():never> $suspension */
            $suspension = EventLoop::getSuspension();
            $this->transferQueue->enqueue([$suspension, $stream, $serialized]);
            EventLoop::enable($this->onWritable);
            if ($closure = $suspension->suspend()) {
                $closure(); // Throw exception in closure for better backtrace.
            }
        }
    }
}
