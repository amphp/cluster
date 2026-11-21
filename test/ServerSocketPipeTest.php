<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\ServerSocketPipeFactory;
use Amp\Cluster\ServerSocketPipeProvider;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\BindContext;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use function Amp\async;

class ServerSocketPipeTest extends AsyncTestCase
{
    public function testProviderAppliesBindContextOptions(): void
    {
        if (!\defined('SO_REUSEPORT')) {
            self::markTestSkipped('SO_REUSEPORT is not supported on this platform');
        }

        [$providerSocket, $factorySocket] = Socket\createSocketPair();

        $provider = new ServerSocketPipeProvider((new BindContext())->withReusePort());
        $future = async(fn () => $provider->provideFor($providerSocket));

        $socketFactory = new ServerSocketPipeFactory($factorySocket);
        $server = $socketFactory->listen('127.0.0.1:0');

        $socket = \socket_import_stream($server->getResource());
        self::assertInstanceOf(\Socket::class, $socket);
        self::assertNotSame(0, \socket_get_option($socket, \SOL_SOCKET, \SO_REUSEPORT));

        $server->close();
        $socketFactory->close();

        $future->await(new TimeoutCancellation(1));
    }

    public function testBindFailureIsReportedAndProviderContinues(): void
    {
        // Occupy an address so the provider fails to bind it.
        $occupiedSocket = Socket\listen('127.0.0.1:0');

        [$providerSocket, $factorySocket] = Socket\createSocketPair();

        $provider = new ServerSocketPipeProvider();
        $future = async(fn () => $provider->provideFor($providerSocket));

        $socketFactory = new ServerSocketPipeFactory($factorySocket);

        try {
            $socketFactory->listen($occupiedSocket->getAddress());
            self::fail('Listening on an address already in use should throw');
        } catch (SocketException $exception) {
            // The message is from the bind failure in the providing process.
            self::assertStringContainsString('Failed binding socket on tcp://127.0.0.1:', $exception->getMessage());
        }

        // The same factory and provider continue to serve later requests.
        $server = $socketFactory->listen('127.0.0.1:0');

        $socketFactory->close();
        $server->close();
        $occupiedSocket->close();

        $future->await(new TimeoutCancellation(1));
    }
}
