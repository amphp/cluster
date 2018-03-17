<?php

namespace Amp\Cluster\Internal;

interface LogWriter {
    public function log(int $time, string $level, string $message);
}