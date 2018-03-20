<?php

namespace Amp\Cluster\Internal;

use Amp\Log\Writer;

final class IpcWriter implements Writer {
    /** @var \Amp\Cluster\Internal\IpcClient */
    private $client;

    public function __construct(IpcClient $client) {
        $this->client = $client;
    }

    public function log(string $level, string $message, array $context) {
        $this->client->send("log", [
            "level" => $level,
            "message" => $message,
            "context" => $context,
        ]);
    }
}
