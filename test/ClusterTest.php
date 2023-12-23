<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\ByteStream\StreamChannel;
use Amp\Cluster\Cluster;
use Amp\Cluster\Watcher;
use Amp\Cluster\WorkerMessage;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Pipeline;
use Amp\Socket;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use function Amp\async;

class ClusterTest extends AsyncTestCase
{
    private Logger $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger('test-logger');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        (static fn () => Cluster::$cluster = null)->bindTo(null, Cluster::class)();
    }

    public function testCreateLogHandlerInParent(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Create your own log handler when not running as part of a cluster');

        Cluster::createLogHandler();
    }

    public function testCreateLogHandlerInWorker(): void
    {
        [$receive, $send] = Socket\createSocketPair();

        $channel = new StreamChannel($receive, $receive);

        $future = async((static fn () => Cluster::run(1, $channel, $send))->bindTo(null, Cluster::class));

        $handler = async(Cluster::createLogHandler(...))->await();

        $channel = new StreamChannel($send, $send);
        $channel->send(null); // Send null to terminate cluster.

        $future->await();

        try {
            $this->assertInstanceOf(HandlerInterface::class, $handler);
        } finally {
            $receive->close();
            $send->close();
        }
    }

    public function testSelectPort(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-select-port.php', $this->logger);

        $count = 3;

        try {
            $watcher->start($count);

            $ports = Pipeline::fromIterable($watcher->getMessageIterator())
                ->take(3)
                ->map(fn (WorkerMessage $m) => $m->getData())
                ->toArray();

            $this->assertCount($count, $ports);
            $this->assertSame(\array_fill(0, $count, $ports[0]), $ports);
        } finally {
            $watcher->stop();
        }
    }
}
