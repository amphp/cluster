<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamChannel;
use Amp\ByteStream\WritableStream;
use Amp\Closable;
use Amp\Cluster\Internal\StreamResourceReceivePipe;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\SerializationException;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use function Amp\async;

final class ServerSocketPipeFactory implements ServerSocketFactory, Closable
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var Channel<never, SocketAddress|null> */
    private readonly Channel $channel;

    /**
     * Receives the bound server socket with its address, or only an error message if binding failed.
     *
     * @var StreamResourceReceivePipe<SocketAddress|string>
     */
    private readonly StreamResourceReceivePipe $pipe;

    public function __construct(WritableStream&ResourceStream $stream)
    {
        $serializer = new NativeSerializer();
        $this->channel = new StreamChannel(new ReadableIterableStream([]), $stream, $serializer);
        $this->pipe = new StreamResourceReceivePipe($stream, $serializer);
    }

    public function __destruct()
    {
        if (!$this->channel->isClosed()) {
            async(self::closeChannel(...), $this->channel)->ignore();
        }
    }

    #[\Override]
    public function close(): void
    {
        self::closeChannel($this->channel);
    }

    private static function closeChannel(Channel $channel): void
    {
        if (!$channel->isClosed()) {
            $channel->send(null);
            $channel->close();
        }
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->channel->onClose($onClose);
    }

    #[\Override]
    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ResourceServerSocket
    {
        $bindContext ??= new BindContext();
        if (!$address instanceof SocketAddress) {
            // Normalize to SocketAddress here to avoid throwing exception for invalid strings at receiving end.
            $address = SocketAddress\fromString($address);
        }

        try {
            $this->channel->send($address);

            $received = $this->pipe->receive();
        } catch (ChannelException|SerializationException $exception) {
            throw new SocketException(
                'Failed sending request to bind server socket: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $stream = $received->getResource();
        if ($stream === null) {
            // The providing process failed to bind the socket and sent the reason instead.
            $message = $received->getData();
            throw new SocketException(\is_string($message) ? $message : 'Failed to bind server socket');
        }

        $context = $bindContext->toStreamContextArray();

        $socket = \socket_import_stream($stream);
        if (!$socket) {
            throw new SocketException('Failed to import stream from socket');
        }

        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        $stream = \socket_export_stream($socket);
        if (!$stream) {
            throw new SocketException('Failed to export stream from socket');
        }

        if (PHP_VERSION_ID >= 80300) {
            /** @psalm-suppress UndefinedFunction, UnusedFunctionCall */
            \stream_context_set_options($stream, $context);
        } else {
            \stream_context_set_option($stream, $context);
        }

        return new ResourceServerSocket($stream, $bindContext);
    }
}
