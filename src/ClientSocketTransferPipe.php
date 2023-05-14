<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\Closable;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Serialization\Serializer;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;

/**
 * @template TReceive
 * @template TSend
 */
final class ClientSocketTransferPipe implements Closable
{
    /** @var StreamResourceReceivePipe<TReceive> */
    private readonly StreamResourceReceivePipe $receive;

    /** @var StreamResourceSendPipe<TSend> */
    private readonly StreamResourceSendPipe $send;

    public function __construct(
        Socket&ResourceStream $socket,
        Serializer $serializer = new NativeSerializer(),
    ) {
        $this->receive = new StreamResourceReceivePipe($socket, $serializer);
        $this->send = new StreamResourceSendPipe($socket, $serializer);
    }

    /**
     * @param positive-int $chunkSize
     *
     * @return TransferredSocket<TReceive>|null
     *
     * @throws SerializationException
     * @throws SocketException
     */
    public function receive(
        ?Cancellation $cancellation = null,
        int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE,
    ): ?TransferredSocket {
        $received = $this->receive->receive($cancellation);
        if (!$received) {
            return null;
        }

        return new TransferredSocket(
            ResourceSocket::fromServerSocket($received->getResource(), $chunkSize),
            $received->getData(),
        );
    }

    /**
     * @param TSend $data
     *
     * @throws SerializationException
     * @throws SocketException
     */
    public function send(Socket&ResourceStream $socket, mixed $data = null): void
    {
        $resource = $socket->getResource();
        if (!\is_resource($resource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $this->send->send($resource, $data);
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
