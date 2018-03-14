<?php

namespace Amp\Cluster;

use Amp\Parallel\Sync\Channel;
use Psr\Log\AbstractLogger;

class IpcLogger extends AbstractLogger {
    private $channel;

    public function __construct(Channel $channel) {
        $this->channel = $channel;
    }

    public function log($level, $message, array $context = []) {
        $this->channel->send([
            "type" => "log",
            "payload" => ["level" => $level, "message" => $message, "context" => $context],
        ]);
    }
}
