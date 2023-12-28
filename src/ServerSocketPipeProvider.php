<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamChannel;
use Amp\ByteStream\WritableBuffer;
use Amp\Cancellation;
use Amp\Cluster\Internal\StreamResourceSendPipe;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Amp\Socket\BindContext;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use const Amp\Process\IS_WINDOWS;

final class ServerSocketPipeProvider
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly BindContext $bindContext;
    private readonly Serializer $serializer;

    private array $servers = [];

    public function __construct(BindContext $bindContext = new BindContext())
    {
        if (canReusePort()) {
            $bindContext = $bindContext->withReusePort();
        }

        $this->bindContext = $bindContext;
        $this->serializer = new NativeSerializer();
    }

    /**
     * @throws SocketException
     */
    public function provideFor(ReadableStream&ResourceStream $stream, ?Cancellation $cancellation = null): void
    {
        /** @var Channel<SocketAddress|null, never> $channel */
        $channel = new StreamChannel($stream, new WritableBuffer(), $this->serializer);
        /** @var StreamResourceSendPipe<SocketAddress> $pipe */
        $pipe = new StreamResourceSendPipe($stream, $this->serializer);

        try {
            while ($address = $channel->receive($cancellation)) {
                /** @psalm-suppress DocblockTypeContradiction Extra manual check to enforce docblock types. */
                if (!$address instanceof SocketAddress) {
                    throw new \ValueError(
                        \sprintf(
                            'Expected only instances of %s on channel; do not use the given socket outside %s',
                            SocketAddress::class,
                            self::class,
                        )
                    );
                }

                $uri = (string) $address;
                $server = $this->servers[$uri] ??= self::bind($uri, $this->bindContext);

                $pipe->send($server, $address);
            }
        } catch (ChannelException $exception) {
            throw new SocketException('Provider channel closed: ' . $exception->getMessage(), previous: $exception);
        } finally {
            $pipe->close();
        }
    }

    /**
     * @return resource
     */
    private static function bind(string $uri, BindContext $bindContext)
    {
        static $errorHandler;

        $context = \stream_context_create(\array_merge(
            $bindContext->toStreamContextArray(),
            [
                "socket" => [
                    "so_reuseaddr" => IS_WINDOWS, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                    "ipv6_v6only" => true,
                ],
            ],
        ));

        // Error reporting suppressed as stream_socket_server() error is immediately checked and
        // reported with an exception.
        \set_error_handler($errorHandler ??= static fn () => true);

        try {
            // Do NOT use STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
            if (!$server = \stream_socket_server($uri, $errno, $errstr, \STREAM_SERVER_BIND, $context)) {
                throw new \RuntimeException(\sprintf(
                    "Failed binding socket on %s: [Err# %s] %s",
                    $uri,
                    $errno,
                    $errstr,
                ));
            }
        } finally {
            \restore_error_handler();
        }

        return $server;
    }
}
