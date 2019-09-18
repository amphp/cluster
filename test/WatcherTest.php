<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Watcher;
use Amp\Delayed;
use Amp\PHPUnit\AsyncTestCase;
use Monolog\Logger;

class WatcherTest extends AsyncTestCase
{
    /** @var Logger */
    private $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger('test-logger');
    }

    public function testDoubleStart(): \Generator
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('The cluster is already running or has already run');

        try {
            yield $watcher->start(1);
            yield $watcher->start(1);
        } finally {
            $watcher->stop();
        }
    }

    public function testInvalidWorkerCount(): \Generator
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('The number of workers must be greater than zero');

        try {
            yield $watcher->start(-1);
        } finally {
            $watcher->stop();
        }
    }

    public function testReceivingMessage(): \Generator
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger);

        $invoked = false;
        $watcher->onMessage('test-event', function (string $message) use (&$invoked) {
            $invoked = true;
            $this->assertSame('test-message', $message);
        });

        try {
            yield $watcher->start(1);
            yield new Delayed(100); // Give worker time to start and send message.
            $this->assertTrue($invoked);
        } finally {
            $watcher->stop();
        }
    }

    public function testRestart(): \Generator
    {
        $watcher = new Watcher(__DIR__ . '/scripts/test-message.php', $this->logger);

        $invoked = 0;
        $watcher->onMessage('test-event', function (string $message) use (&$invoked) {
            ++$invoked;
            $this->assertSame('test-message', $message);
        });

        try {
            yield $watcher->start(1);
            yield new Delayed(100); // Give worker time to start and send message.
            $this->assertSame(1, $invoked);

            yield $watcher->restart();
            yield new Delayed(100); // Give worker time to start and send message.
            $this->assertSame(2, $invoked);
        } finally {
            $watcher->stop();
        }
    }
}
