<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Cluster;
use Amp\Cluster\ConsoleLogger;

final class IpcWriter implements LogWriter {
    public function log(int $time, string $level, string $message) {
        Cluster::send([
            "type" => ConsoleLogger::class,
            "time" => $time,
            "level" => $level,
            "message" => $message,
        ]);
    }
}