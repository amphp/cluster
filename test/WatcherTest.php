<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Watcher;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use Monolog\Logger;
use function Amp\delay;

class WatcherTest extends AsyncTestCase
{
    private Logger $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger('test-logger');
    }

    public function testDoubleStart(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', new LocalIpcHub, $this->logger);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('The cluster is already running or has already run');

        try {
            $watcher->start(1);
            $watcher->start(1);
        } finally {
            $watcher->stop();
        }
    }

    public function testInvalidWorkerCount(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', new LocalIpcHub, $this->logger);

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
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', new LocalIpcHub, $this->logger);

        $invoked = false;
        $watcher->onMessage(function (string $message) use (&$invoked) {
            $invoked = true;
            $this->assertSame('test-message', $message);
        });

        try {
            $watcher->start(1);
            delay(0.1); // Give worker time to start and send message.
            $this->assertTrue($invoked);
        } finally {
            $watcher->stop();
        }
    }

    public function testRestart(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', new LocalIpcHub, $this->logger);

        $invoked = 0;
        $watcher->onMessage(function (string $message) use (&$invoked) {
            ++$invoked;
            $this->assertSame('test-message', $message);
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
    }

    public function testGracefulSelfTermination(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-graceful-self-terminate.php', new LocalIpcHub, $this->logger);

        $invoked = 0;
        $watcher->onMessage(function (string $message) use (&$invoked) {
            ++$invoked;
            $this->assertSame('Initiating shutdown', $message);
        });

        $watcher->start(1);
        delay(0.1); // Give worker time to start.
        $watcher->broadcast(null);

        $watcher->stop(new TimeoutCancellation(0.1)); // Give worker time to stop.
        $this->assertSame(1, $invoked);
    }

    public function testGracefulWatcherTermination(): void
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-graceful-terminate-worker.php', new LocalIpcHub, $this->logger);

        $invoked = 0;
        $watcher->onMessage(function (string $message) use (&$invoked, $watcher) {
            $this->assertSame(match (++$invoked) {
                1 => 'Active',
                2 => 'Adios',
            }, $message);
            if ($invoked === 1) {
                $watcher->stop(new TimeoutCancellation(0.1)); // Give worker time to stop.
                echo "hey";
            }
        });

        $watcher->start(1);
        $watcher->join();
        $this->assertSame(2, $invoked);
    }
}
