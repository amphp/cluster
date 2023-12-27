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

    /** @var StreamResourceSendPipe<T> */
    private readonly StreamResourceSendPipe $send;

    public function __construct(
        ResourceStream $resourceStream,
        Serializer $serializer = new NativeSerializer(),
    ) {
        $this->send = new StreamResourceSendPipe($resourceStream, $serializer);
    }

    /**
     * @param T $data
     *
     * @throws SerializationException
     * @throws SocketException
     */
    public function send(ResourceStream $resourceStream, mixed $data = null): void
    {
        $resource = $resourceStream->getResource();
        if (!\is_resource($resource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $this->send->send($resource, $data);
    }

    public function close(): void
    {
        $this->send->close();
    }

    public function isClosed(): bool
    {
        return $this->send->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->send->onClose($onClose);
    }
}
