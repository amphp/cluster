<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\StreamChannel;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Amp\Socket\BindContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use const Amp\Process\IS_WINDOWS;
use function Amp\async;

final class ClusterServerSocketProvider
{
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
     * @return Future<void>
     *
     * @throws SocketException
     */
    public function provideFor(Socket&ResourceStream $socket, ?Cancellation $cancellation = null): Future
    {
        /** @var Channel<SocketAddress|null, never> $channel */
        $channel = new StreamChannel($socket, $socket, $this->serializer);
        $pipe = new StreamResourceSendPipe($socket, $this->serializer);

        $servers = &$this->servers;
        $bindContext = $this->bindContext;
        return async(static function () use (&$servers, $channel, $pipe, $bindContext, $cancellation): void {
            try {
                while ($address = $channel->receive($cancellation)) {
                    /** @psalm-suppress DocblockTypeContradiction Extra manual check to enforce docblock types. */
                    if (!$address instanceof SocketAddress) {
                        throw new \ValueError(\sprintf(
                            'Expected only instances of %s on channel; do not use the given socket outside %s',
                            SocketAddress::class,
                            self::class,
                        ));
                    }

                    $uri = (string) $address;
                    $server = $servers[$uri] ??= self::listen($uri, $bindContext);

                    $pipe->send($server, $address);
                }
            } catch (CancelledException) {
                // Providing cancelled by $cancellation.
            } catch (ChannelException) {
                // Sending context closed the channel abruptly.
            } finally {
                $pipe->close();
            }
        });
    }

    /**
     * @return resource
     */
    private static function listen(string $uri, BindContext $bindContext)
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
