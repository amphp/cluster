<?php

namespace Amp\Cluster\Internal;

use Amp\ByteStream\OutputStream;
use Psr\Log\LogLevel;
use function Amp\Cluster\hasColorSupport;

final class StreamWriter implements LogWriter {
    /** @var OutputStream */
    private $stream;

    /** @var bool */
    private $colors;

    public function __construct(OutputStream $outputStream) {
        $this->stream = $outputStream;
        $this->setAnsiColorOption();
    }

    public function log(int $time, string $level, string $message) {
        $level = $this->ansify($level);
        $this->stream->write(@\date("Y-m-d H:i:s", $time) . " {$level} {$message}\r\n");
    }

    private function setAnsiColorOption() {
        $value = \getenv('AMP_CLUSTER_COLOR');
        if ($value === false || $value === '') {
            $value = 'auto';
        }

        $value = \strtolower($value);
        switch ($value) {
            case "1":
            case "true":
            case "on":
                $this->colors = true;
                break;
            case "0":
            case "false":
            case "off":
                $this->colors = false;
                break;
            default:
                $this->colors = hasColorSupport();
                break;
        }
    }

    private function ansify(string $level): string {
        switch ($level) {
            case LogLevel::EMERGENCY:
            case LogLevel::ALERT:
            case LogLevel::CRITICAL:
            case LogLevel::ERROR:
                return "\033[1;31m{$level}\033[0m"; // bold + red
            case LogLevel::WARNING:
                return "\033[1;33m{$level}\033[0m"; // bold + yellow
            case LogLevel::NOTICE:
                return "\033[1;32m{$level}\033[0m"; // bold + green
            case LogLevel::INFO:
                return "\033[1;35m{$level}\033[0m"; // bold + magenta
            case LogLevel::DEBUG:
                return "\033[1;36m{$level}\033[0m"; // bold + cyan
            default:
                return "\033[1m{$level}\033[0m"; // bold
        }
    }
}