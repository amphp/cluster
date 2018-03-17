<?php

namespace Amp\Cluster;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Cluster\Internal\IpcWriter;
use Amp\Cluster\Internal\LogWriter;
use Amp\Cluster\Internal\StreamWriter;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class ConsoleLogger extends AbstractLogger {
    const LEVELS = [
        LogLevel::DEBUG => 8,
        LogLevel::INFO => 7,
        LogLevel::NOTICE => 6,
        LogLevel::WARNING => 5,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 3,
        LogLevel::ALERT => 2,
        LogLevel::EMERGENCY => 1,
    ];

    /** @var int */
    private $outputLevel;

    /** @var LogWriter */
    private $writer;

    public function __construct(string $logLevel = LogLevel::INFO) {
        $this->setOutputLevel($logLevel);

        if (Cluster::isWorker()) {
            $this->writer = new IpcWriter;
        } else {
            $this->writer = new StreamWriter(new ResourceOutputStream(\STDOUT));
        }
    }

    private function setOutputLevel(string $logLevel) {
        if (!isset(self::LEVELS[$logLevel])) {
            throw new \Error("Invalid log level: {$logLevel}");
        }

        $this->outputLevel = self::LEVELS[$logLevel];
    }

    public function log($level, $message, array $context = []) {
        if (!$this->shouldEmit($level)) {
            return;
        }

        $message = $this->formatMessage($message, $context);
        $time = $context["time"] ?? time();
        $this->writer->log($time, $level, $message);
    }

    private function shouldEmit(string $logLevel): bool {
        return isset(self::LEVELS[$logLevel])
            ? ($this->outputLevel >= self::LEVELS[$logLevel])
            : false;
    }

    private function formatMessage(string $message, array $context = []): string {
        foreach ($context as $key => $replacement) {
            // avoid invalid casts to string
            if (!\is_array($replacement) && (!\is_object($replacement) || \method_exists($replacement, '__toString'))) {
                $replacements["{{$key}}"] = $replacement;
            }
        }

        if (isset($replacements)) {
            $message = \strtr($message, $replacements);
        }

        // Strip any control characters...
        return preg_replace('/[\x00-\x1F\x7F]/', '', $message);
    }
}
