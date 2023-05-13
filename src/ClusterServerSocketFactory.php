<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamChannel;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use function Amp\async;

final class ClusterServerSocketFactory implements ServerSocketFactory
{
    /** @var Channel<never, SocketAddress|null> */
    private readonly Channel $channel;

    private readonly StreamResourceReceivePipe $pipe;

    public function __construct(Socket&ResourceStream $socket)
    {
        $serializer = new NativeSerializer();
        $this->channel = new StreamChannel($socket, $socket, $serializer);
        $this->pipe = new StreamResourceReceivePipe($socket, $serializer);
    }

    public function __destruct()
    {
        if ($this->channel->isClosed()) {
            return;
        }

        $channel = $this->channel;
        async(static fn () => $channel->send(null))->ignore();
    }

    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ServerSocket
    {
        $bindContext ??= new BindContext();
        if (!$address instanceof SocketAddress) {
            // Normalize to SocketAddress here to avoid throwing exception for invalid strings at receiving end.
            $address = SocketAddress\fromString($address);
        }

        try {
            $this->channel->send($address);

            $received = $this->pipe->receive();
            if (!$received) {
                throw new SocketException('Transfer pipe closed before server socket was received');
            }
        } catch (ChannelException|SerializationException $exception) {
            throw new SocketException(
                'Failed sending request to bind server socket: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $context = $bindContext->toStreamContextArray();

        [$stream] = $received;

        $socket = \socket_import_stream($stream);
        if (!$socket) {
            throw new SocketException('Failed to import stream from socket');
        }

        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        $stream = \socket_export_stream($socket);
        \stream_context_set_option($stream, $context);

        return new ResourceServerSocket($stream, $bindContext);
    }
}
