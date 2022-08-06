<?php

namespace Amp\Cluster;

use Amp\ByteStream\StreamChannel;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceSocketServer;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Socket\SocketServer;
use Amp\Socket\SocketServerFactory;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;

final class ClusterSocketServerFactory implements SocketServerFactory
{
    /** @var Channel<never, SocketAddress> */
    private readonly Channel $channel;

    private readonly StreamResourceTransferPipe $pipe;

    public function __construct(Socket $socket)
    {
        $serializer = new NativeSerializer();
        $this->channel = new StreamChannel($socket, $socket, $serializer);
        $this->pipe = new StreamResourceTransferPipe($socket, $serializer);
    }

    public function listen(SocketAddress $address, ?BindContext $bindContext = null): SocketServer
    {
        $bindContext ??= new BindContext();

        try {
            $this->channel->send($address);

            $received = $this->pipe->receive();
            if (!$received) {
                throw new SocketException('Transfer pipe closed server socket was received');
            }
        } catch (ChannelException|SerializationException $exception) {
            throw new SocketException(
                'Failed sending request to bind server socket: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        $context = $bindContext->toStreamContextArray();

        [$stream] = $received;

        $socket = \socket_import_stream($stream);
        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        $stream = \socket_export_stream($socket);
        \stream_context_set_option($stream, $context);

        return new ResourceSocketServer($stream, $bindContext);
    }
}
