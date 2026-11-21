<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\Closable;
use Amp\Cluster\Internal\StreamResourceSendPipe;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\SocketException;

/**
 * @template T
 */
final class ClientSocketSendPipe implements Closable
{
    use ForbidCloning;
    use ForbidSerialization;

    /** Default maximum length of the serialized data sent with each socket. */
    public const DEFAULT_DATA_LENGTH_LIMIT = Internal\TransferSocket::DEFAULT_DATA_LENGTH_LIMIT;

    /** @var StreamResourceSendPipe<T> */
    private readonly StreamResourceSendPipe $send;

    /**
     * @param positive-int $dataLengthLimit Maximum length of the serialized data sent with each socket. Should match
     *     the limit given to the receiving {@see ClientSocketReceivePipe}.
     */
    public function __construct(
        ResourceStream $resourceStream,
        Serializer $serializer = new NativeSerializer(),
        int $dataLengthLimit = self::DEFAULT_DATA_LENGTH_LIMIT,
    ) {
        $this->send = new StreamResourceSendPipe($resourceStream, $serializer, $dataLengthLimit);
    }

    /**
     * @param T $data Data to send with the socket. The serialized data may not exceed the data length limit given
     *     to the constructor.
     *
     * @throws SerializationException If the data cannot be serialized.
     * @throws SocketException If the serialized data exceeds the data length limit or the socket cannot be
     *     transferred.
     */
    public function send(ResourceStream $resourceStream, mixed $data = null): void
    {
        $resource = $resourceStream->getResource();
        if (!\is_resource($resource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $this->send->send($resource, $data);
    }

    #[\Override]
    public function close(): void
    {
        $this->send->close();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->send->isClosed();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->send->onClose($onClose);
    }
}
