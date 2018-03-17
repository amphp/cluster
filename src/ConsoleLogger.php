<?php

namespace Amp\Cluster;

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

    private $outputLevel = self::LEVELS[LogLevel::DEBUG];
    private $colors = true;
    private $console;

    public function __construct(Console $console) {
        $this->console = $console;

        if ($console->hasArgument("color")) {
            $value = $console->getArgument("color");
            $this->setAnsiColorOption($value);
        }

        if ($console->hasArgument("log")) {
            $level = $console->getArgument("log");
            $level = self::LEVELS[$level] ?? $level;
            $this->setOutputLevel($level);
        }
    }

    private function setAnsiColorOption($value) {
        $value = ($value === "") ? "on" : $value;

        switch ($value) {
            case "auto":
            case "on":
                $this->colors = true;
                break;
            case "off":
                $this->colors = false;
                break;
            default:
                $this->colors = true;
                break;
        }

        if ($value === "on") {
            $this->console->forceAnsiOn();
        }
    }

    private function setOutputLevel(int $outputLevel) {
        if ($outputLevel < \min(self::LEVELS)) {
            $outputLevel = \min(self::LEVELS);
        } elseif ($outputLevel > \max(self::LEVELS)) {
            $outputLevel = \max(self::LEVELS);
        }

        $this->outputLevel = $outputLevel;
    }

    public function log($level, $message, array $context = []) {
        if ($this->canEmit($level)) {
            $message = $this->format($level, $message, $context);
            $this->console->output($message);
        }
    }

    private function canEmit(string $logLevel) {
        return isset(self::LEVELS[$logLevel])
            ? ($this->outputLevel >= self::LEVELS[$logLevel])
            : false;
    }

    private function format($level, $message, array $context = []) {
        $time = @date("Y-m-d H:i:s", $context["time"] ?? time());
        $level = isset(self::LEVELS[$level]) ? $level : "unknown";
        $level = $this->colors ? $this->ansify($level) : "$level:";

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
        $message = preg_replace('/[\x00-\x1F\x7F]/', '', $message);

        return "[{$time}] {$level} {$message}";
    }

    private function ansify($level) {
        switch ($level) {
            case LogLevel::EMERGENCY:
            case LogLevel::ALERT:
            case LogLevel::CRITICAL:
            case LogLevel::ERROR:
                return "<bold><red>{$level}</red></bold>";
            case LogLevel::WARNING:
                return "<bold><yellow>{$level}</yellow></bold>";
            case LogLevel::NOTICE:
                return "<bold><green>{$level}</green></bold>";
            case LogLevel::INFO:
                return "<bold><magenta>{$level}</magenta></bold>";
            case LogLevel::DEBUG:
                return "<bold><cyan>{$level}</cyan></bold>";
            default:
                return "<bold>{$level}</bold>";
        }
    }
}
