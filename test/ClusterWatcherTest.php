<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\ClusterWatcher;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use function Amp\async;
use function Amp\delay;
use function Amp\now;

class ClusterWatcherTest extends AsyncTestCase
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
        $watcher = new ClusterWatcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub());

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
        $watcher = new ClusterWatcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub());

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
        $watcher = new ClusterWatcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub());

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
        $watcher = new ClusterWatcher(__DIR__ . '/scripts/test-message.php', $this->logger, new LocalIpcHub());

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
        $watcher = new ClusterWatcher(
            script: __DIR__ . '/scripts/test-graceful-self-terminate.php',
            logger: $this->logger,
            hub: new LocalIpcHub(),
        );

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

    public function testRestartDelayedForRepeatedlyFailingWorker(): void
    {
        $handler = new TestHandler();
        $this->logger->pushHandler($handler);

        $watcher = new ClusterWatcher(__DIR__ . '/scripts/test-crash.php', $this->logger, new LocalIpcHub());

        /** @var list<float> $startTimes */
        $startTimes = [];
        $future = async(function () use (&$startTimes, $watcher): void {
            foreach ($watcher->getMessageIterator() as $message) {
                $this->assertSame('started', $message->getData());
                $startTimes[] = now();
            }
        });

        $watcher->start(1);

        // Without a delay, the crashing worker would be restarted dozens of times within this period. With delays of
        // 1 second after the first crash and 2 seconds after the second, the worker is started only twice.
        delay(3);

        self::assertCount(2, $startTimes);
        [$startTime, $restartTime] = $startTimes;
        self::assertGreaterThanOrEqual(1, $restartTime - $startTime);

        self::assertTrue($handler->hasWarningThatContains('delaying restart by 1 seconds'));
        self::assertTrue($handler->hasWarningThatContains('delaying restart by 2 seconds'));

        // Stopping while waiting to restart a worker must not wait for the delay to elapse.
        $stoppedAt = now();
        $watcher->stop();
        self::assertLessThan(0.5, now() - $stoppedAt);

        $future->await();
    }

    public function testGracefulWatcherTermination(): void
    {
        $watcher = new ClusterWatcher(
            script: __DIR__ . '/scripts/test-graceful-terminate-worker.php',
            logger: $this->logger,
            hub: new LocalIpcHub(),
        );

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

    public function testLogHandlerFailureDoesNotAffectWorker(): void
    {
        $testHandler = new TestHandler();
        $this->logger->pushHandler($testHandler);

        // Pushed last so it is called before the test handler.
        $this->logger->pushHandler(new class extends AbstractProcessingHandler {
            protected function write(LogRecord $record): void
            {
                throw new \RuntimeException('Log handler failure');
            }
        });

        /** @var list<string> $failedRecords */
        $failedRecords = [];
        $this->logger->setExceptionHandler(
            function (\Throwable $exception, LogRecord $record) use (&$failedRecords): void {
                $this->assertSame('Log handler failure', $exception->getMessage());
                $failedRecords[] = $record->message;
            },
        );

        $watcher = new ClusterWatcher(__DIR__ . '/scripts/test-log.php', $this->logger, new LocalIpcHub());
        $watcher->start(1);

        try {
            $iterator = $watcher->getMessageIterator();

            self::assertTrue($iterator->continue());
            $first = $iterator->getValue();

            self::assertTrue($iterator->continue());
            $second = $iterator->getValue();
        } finally {
            $watcher->stop();
        }

        self::assertSame('logged', $first->getData());
        self::assertSame('logged again', $second->getData());

        // Both messages were sent by the same worker, so it was not restarted after the handler failures.
        self::assertSame($first->getWorker()->getId(), $second->getWorker()->getId());

        // The worker's records reached the remaining handler and each failure was reported to the exception handler.
        self::assertTrue($testHandler->hasInfoThatContains('First log record'));
        self::assertTrue($testHandler->hasInfoThatContains('Second log record'));
    }

    public function testUnexpectedWorkerFailureRestartsWorker(): void
    {
        $handler = new TestHandler();
        $this->logger->pushHandler($handler);

        $watcher = new ClusterWatcher(
            script: __DIR__ . '/scripts/test-malformed-message.php',
            logger: $this->logger,
            hub: new LocalIpcHub(),
        );

        $watcher->start(1);

        try {
            $iterator = $watcher->getMessageIterator();

            self::assertTrue($iterator->continue());
            $first = $iterator->getValue();

            // The replacement is started after a 1-second delay, as the first worker failed soon after starting.
            self::assertTrue($iterator->continue());
            $second = $iterator->getValue();
        } finally {
            $watcher->stop();
        }

        self::assertSame('started', $first->getData());
        self::assertSame('started', $second->getData());
        self::assertNotSame($first->getWorker()->getId(), $second->getWorker()->getId());

        self::assertTrue($handler->hasErrorThatContains(\sprintf(
            'Worker %d failed: ',
            $first->getWorker()->getId(),
        )));
    }
}
