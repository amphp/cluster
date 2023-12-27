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

    /** @var StreamResourceReceivePipe<T> */
    private readonly StreamResourceReceivePipe $receive;

    public function __construct(
        ResourceStream $resourceStream,
        Serializer $serializer = new NativeSerializer(),
    ) {
        $this->receive = new StreamResourceReceivePipe($resourceStream, $serializer);
    }

    /**
     * @param positive-int $chunkSize
     *
     * @return TransferredSocket<T>
     *
     * @throws SerializationException
     * @throws SocketException
     */
    public function receive(
        ?Cancellation $cancellation = null,
        int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE,
    ): TransferredSocket {
        $received = $this->receive->receive($cancellation);

        return new TransferredSocket(
            ResourceSocket::fromServerSocket($received->getResource(), $chunkSize),
            $received->getData(),
        );
    }

    public function close(): void
    {
        $this->receive->close();
    }

    public function isClosed(): bool
    {
        return $this->receive->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->receive->onClose($onClose);
    }
}
