<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\ByteStream\ResourceStream;
use Amp\Closable;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\SocketException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * @template T
 * @internal
 */
final class StreamResourceSendPipe implements Closable
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly TransferSocket $transferSocket;

    /** @var \SplQueue<array{Suspension<null|\Closure():never>, TransferMessage}> */
    private readonly \SplQueue $transferQueue;

    private readonly string $onWritable;

    /**
     * @param positive-int $dataLengthLimit Maximum length of the serialized data sent with each socket.
     */
    public function __construct(
        ResourceStream $resourceStream,
        private readonly Serializer $serializer,
        int $dataLengthLimit = TransferSocket::DEFAULT_DATA_LENGTH_LIMIT,
    ) {
        $this->transferSocket = $transferSocket = new TransferSocket($resourceStream, $dataLengthLimit);
        $this->transferQueue = $transferQueue = new \SplQueue();

        $streamResource = $resourceStream->getResource();
        if (!\is_resource($streamResource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $this->onWritable = $onWritable = EventLoop::disable(EventLoop::onWritable(
            $streamResource,
            static function (string $callbackId, $stream) use (
                $transferSocket,
                $transferQueue,
            ): void {
                if (\feof($stream)) {
                    $transferSocket->close();
                    return;
                }

                while (!$transferQueue->isEmpty()) {
                    /**
                     * @var Suspension<null|\Closure():never> $suspension
                     * @var TransferMessage $message
                     */
                    [$suspension, $message] = $transferQueue->shift();

                    try {
                        $message = $transferSocket->sendMessage($message);

                        if ($message) {
                            $transferQueue->unshift([$suspension, $message]);
                            return;
                        }

                        $suspension->resume();
                    } catch (\Throwable $exception) {
                        // A partially sent message leaves the stream unusable for further transfers.
                        if ($message?->initiated) {
                            $transferSocket->close();
                        }

                        $suspension->resume(static fn () => throw new SocketException(
                            'Failed to send socket: ' . $exception->getMessage(),
                            previous: $exception,
                        ));
                    }
                }

                EventLoop::disable($callbackId);
            },
        ));

        $this->transferSocket->onClose(static function () use ($transferQueue, $onWritable): void {
            EventLoop::cancel($onWritable);

            while (!$transferQueue->isEmpty()) {
                /** @var Suspension<null|\Closure():never> $suspension */
                [$suspension] = $transferQueue->dequeue();
                $suspension->resume(
                    static fn () => throw new SocketException('The transfer socket closed unexpectedly')
                );
            }
        });
    }

    #[\Override]
    public function close(): void
    {
        $this->transferSocket->close();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->transferSocket->isClosed();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->transferSocket->onClose($onClose);
    }

    /**
     * @param resource|null $stream Stream socket resource to transfer, or null to send only the data.
     * @param T $data
     *
     * @throws SocketException
     * @throws SerializationException
     */
    public function send(mixed $stream, mixed $data = null): void
    {
        $message = $this->transferSocket->createMessage($this->serializer->serialize($data), $stream);

        if ($this->transferQueue->isEmpty()) {
            $message = $this->transferSocket->sendMessage($message);
            if (!$message) {
                return;
            }
        }

        /** @var Suspension<null|\Closure():never> $suspension */
        $suspension = EventLoop::getSuspension();
        $this->transferQueue->push([$suspension, $message]);
        EventLoop::enable($this->onWritable);
        if ($closure = $suspension->suspend()) {
            $closure(); // Throw exception in closure for better backtrace.
        }
    }
}
