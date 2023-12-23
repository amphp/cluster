<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Watcher;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use Monolog\Logger;
use function Amp\async;
use function Amp\delay;

class WatcherTest extends AsyncTestCase
{
    private Logger $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger('test-logger');

        $this->setTimeout(5);
    }

    public function testDoubleStart(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('The cluster watcher is already running or has already run');

        try {
            $watcher->start(1);
            $watcher->start(1);
        } finally {
            $watcher->stop();
        }
    }

    public function testInvalidWorkerCount(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('The number of workers must be greater than zero');

        try {
            $watcher->start(-1);
        } finally {
            $watcher->stop();
        }
    }

    public function testReceivingMessage(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub);

        $invoked = false;
        $future = async(function () use (&$invoked, $watcher): void {
            foreach ($watcher->getMessageIterator() as $message) {
                $invoked = true;
                $this->assertSame('test-message', $message->getData());
            }
        });

        try {
            $watcher->start(1);
            delay(0.1); // Give worker time to start and send message.
            $this->assertTrue($invoked);
        } finally {
            $watcher->stop();
        }

        $future->await();
    }

    public function testRestart(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub);

        $invoked = 0;
        $future = async(function () use (&$invoked, $watcher): void {
            foreach ($watcher->getMessageIterator() as $message) {
                ++$invoked;
                $this->assertSame('test-message', $message->getData());
            }
        });

        try {
            $watcher->start(1);
            delay(0.1); // Give worker time to start and send message.
            $this->assertSame(1, $invoked);

            $watcher->restart();
            delay(0.1); // Give worker time to start and send message.
            $this->assertSame(2, $invoked);
        } finally {
            $watcher->stop();
        }

        $future->await();
    }

    public function testGracefulSelfTermination(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-graceful-self-terminate.php', $this->logger, new LocalIpcHub);

        $invoked = 0;
        $future = async(function () use (&$invoked, $watcher): void {
            foreach ($watcher->getMessageIterator() as $message) {
                ++$invoked;
                $this->assertSame('Initiating shutdown', $message->getData());
            }
        });

        $watcher->start(1);
        delay(0.1); // Give worker time to start.
        $watcher->broadcast(null);

        $watcher->stop(new TimeoutCancellation(0.1)); // Give worker time to stop.
        $this->assertSame(1, $invoked);

        $future->await();
    }

    public function testGracefulWatcherTermination(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-graceful-terminate-worker.php', $this->logger, new LocalIpcHub);

        $received = 0;

        $watcher->start(1);

        foreach ($watcher->getMessageIterator() as $message) {
            $this->assertSame(match (++$received) {
                1 => 'Active',
                2 => 'Adios',
            }, $message->getData());

            if ($received === 1) {
                async($watcher->stop(...), new TimeoutCancellation(0.1)); // Give worker time to stop.
            }
        }

        $this->assertSame(2, $received);
    }
}
