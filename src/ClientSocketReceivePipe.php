<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\Closable;
use Amp\Cluster\Internal\StreamResourceReceivePipe;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\ResourceSocket;
use Amp\Socket\SocketException;

/**
 * @template-covariant T
 */
final class ClientSocketReceivePipe implements Closable
{
    use ForbidCloning;
    use ForbidSerialization;

    /** Default maximum length of the serialized data received with each socket. */
    public const DEFAULT_DATA_LENGTH_LIMIT = Internal\TransferSocket::DEFAULT_DATA_LENGTH_LIMIT;

    /** @var StreamResourceReceivePipe<T> */
    private readonly StreamResourceReceivePipe $receive;

    /**
     * @param positive-int $dataLengthLimit Maximum length of the serialized data received with each socket. Should
     *     match the limit given to the sending {@see ClientSocketSendPipe}.
     */
    public function __construct(
        ResourceStream $resourceStream,
        Serializer $serializer = new NativeSerializer(),
        int $dataLengthLimit = self::DEFAULT_DATA_LENGTH_LIMIT,
    ) {
        $this->receive = new StreamResourceReceivePipe($resourceStream, $serializer, $dataLengthLimit);
    }

    /**
     * @param positive-int $chunkSize
     *
     * @return TransferredSocket<T>
     *
     * @throws SerializationException If the received data cannot be unserialized.
     * @throws SocketException If the transfer socket closes before receiving a socket or the peer sends data without
     *     a socket, in which case the pipe is closed.
     */
    public function receive(
        ?Cancellation $cancellation = null,
        int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE,
    ): TransferredSocket {
        $received = $this->receive->receive($cancellation);

        $resource = $received->getResource();
        if ($resource === null) {
            // ClientSocketSendPipe always sends a socket, so the peer is not using the expected protocol.
            $this->receive->close();
            throw new SocketException('Received data without a socket; the transfer pipe has been closed');
        }

        return new TransferredSocket(
            ResourceSocket::fromServerSocket($resource, $chunkSize),
            $received->getData(),
        );
    }

    #[\Override]
    public function close(): void
    {
        $this->receive->close();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->receive->isClosed();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->receive->onClose($onClose);
    }
}
