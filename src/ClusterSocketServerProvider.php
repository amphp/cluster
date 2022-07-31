<?php

namespace Amp\Cluster;

use Amp\ByteStream\StreamChannel;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Amp\Socket\BindContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Sync\Channel;
use Revolt\EventLoop;
use const Amp\Process\IS_WINDOWS;

final class ClusterSocketServerProvider
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

    public function provideFor(Socket $socket): void
    {
        /** @var Channel<SocketAddress, never> $channel */
        $channel = new StreamChannel($socket, $socket, $this->serializer);
        $pipe = new Internal\SocketTransferPipe($socket, $this->serializer);

        $servers = &$this->servers;
        $bindContext = $this->bindContext;
        EventLoop::queue(static function () use (&$servers, $channel, $pipe, $bindContext): void {
            static $errorHandler;

            while ($address = $channel->receive()) {
                if (!$address instanceof SocketAddress) {
                    throw new \ValueError(
                        'Expected only instances of %s on channel; do not use the given channel outside %s',
                        SocketAddress::class,
                        self::class,
                    );
                }

                $uri = $address->toString();

                $server = $servers[$uri] ?? null;
                if ($server === null) {
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
                        if (!$server = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context)) {
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

                    $servers[$uri] = $server;
                }

                $pipe->send($server, $address);
            }
        });
    }
}
