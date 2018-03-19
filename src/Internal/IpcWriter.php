<?php

namespace Amp\Cluster\Internal;

use Amp\Log\Writer;

final class IpcWriter implements Writer {
    /** @var \Amp\Cluster\Internal\IpcClient */
    private $client;

    public function __construct(IpcClient $client) {
        $this->client = $client;
    }

    public function log(int $time, string $level, string $message) {
        $this->client->send("log", [
            "time" => $time,
            "level" => $level,
            "message" => $message,
        ]);
    }
}
