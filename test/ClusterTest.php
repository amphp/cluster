<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
use Amp\Cluster\Watcher;
use Amp\Delayed;
use Amp\Parallel\Sync\ChannelledStream;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Socket;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;

class ClusterTest extends AsyncTestCase
{
    /** @var Logger */
    private $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger('test-logger');
    }

    public function testCreateLogHandlerInParent(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Create your own log handler when not running as part of a cluster');

        Cluster::createLogHandler();
    }

    public function testCreateLogHandlerInWorker(): \Generator
    {
        [$receive, $send] = Socket\createPair();

        $channel = new ChannelledStream($receive, $receive);

        $promise = (function () use ($channel, $send): Promise {
            return static::run($channel, $send);
        })->bindTo(null, Cluster::class)();

        $handler = Cluster::createLogHandler();

        $channel = new ChannelledStream($send, $send);
        yield $channel->send(null); // Send null to terminate cluster.

        yield $promise;

        try {
            $this->assertInstanceOf(HandlerInterface::class, $handler);
        } finally {
            $receive->close();
            $send->close();
        }
    }

    public function testOnTerminate(): \Generator
    {
        [$receive, $send] = Socket\createPair();

        $channel = new ChannelledStream($receive, $receive);

        $promise = (function () use ($channel, $send): Promise {
            return static::run($channel, $send);
        })->bindTo(null, Cluster::class)();

        $invoked = false;
        Cluster::onTerminate(function () use (&$invoked): void {
            $invoked = true;
        });

        $channel = new ChannelledStream($send, $send);
        yield $channel->send(null); // Send null to terminate cluster.

        yield $promise;

        try {
            $this->assertTrue($invoked);
        } finally {
            $receive->close();
            $send->close();
        }
    }

    public function testSelectPort(): \Generator
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-select-port.php', $this->logger);

        $ports = [];
        $watcher->onMessage('port-number', function (int $port) use (&$ports): void {
            $ports[] = $port;
        });

        $count = 3;

        try {
            yield $watcher->start($count);
            yield new Delayed(100); // Give workers time to start and send message.
            $this->assertCount($count, $ports);
            $this->assertSame(\array_fill(0, $count, $ports[0]), $ports);
        } finally {
            $watcher->stop();
        }
    }
}
