<?php

namespace Amp\Cluster;

use Amp\Closable;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class StreamResourceSendPipe implements Closable
{
    private readonly Internal\TransferSocket $transferSocket;

    /** @var \SplDoublyLinkedList<array{Suspension<null|Closure():never>, resource, string}> */
    private readonly \SplDoublyLinkedList $transferQueue;

    private readonly string $onWritable;

    public function __construct(
        private readonly Socket $socket,
        private readonly Serializer $serializer,
    ) {
        $this->transferSocket = $transferSocket = new Internal\TransferSocket($socket);
        $this->transferQueue = $transferQueue = new \SplDoublyLinkedList();

        $streamResource = $socket->getResource();
        if (!\is_resource($streamResource)) {
            throw new SocketException('The provided socket has already been closed');
        }

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
                    [$suspension, $export, $data] = $transferQueue->shift();

                    try {
                        if (!$transferSocket->sendSocket($export, $data)) {
                            $transferQueue->unshift([$suspension, $export, $data]);
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

        $this->socket->onClose(static function () use ($transferQueue, $onWritable): void {
            EventLoop::cancel($onWritable);

            while (!$transferQueue->isEmpty()) {
                /** @var Suspension<null|Closure():never> $suspension */
                [$suspension] = $transferQueue->dequeue();
                $suspension->resume(
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
            $this->transferQueue->push([$suspension, $stream, $serialized]);
            EventLoop::enable($this->onWritable);
            if ($closure = $suspension->suspend()) {
                $closure(); // Throw exception in closure for better backtrace.
            }
        }
    }
}