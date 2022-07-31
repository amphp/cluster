<?php

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\Closable;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;

final class ClientTransferPipe implements Closable
{
    private Internal\SocketTransferPipe $pipe;

    public function __construct(
        Socket $socket,
        Serializer $serializer = new NativeSerializer(),
    ) {
        $this->pipe = new Internal\SocketTransferPipe($socket, $serializer);
    }

    /**
     * @param Cancellation|null $cancellation
     * @param positive-int $chunkSize
     *
     * @return array{Socket, mixed}|null
     *
     * @throws SerializationException
     * @throws SocketException
     */
    public function receive(
        ?Cancellation $cancellation = null,
        int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE,
    ): ?array {
        $received = $this->pipe->receive($cancellation);
        if (!$received) {
            return null;
        }

        [$resource, $data] = $received;

        return [ResourceSocket::fromServerSocket($resource, $chunkSize), $data];
    }

    public function send(Socket $socket, mixed $data = null): void
    {
        $resource = $socket->getResource();
        if (!\is_resource($resource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $this->pipe->send($resource, $data);
    }

    public function close(): void
    {
        $this->pipe->close();
    }

    public function isClosed(): bool
    {
        return $this->pipe->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->pipe->onClose($onClose);
    }
}
