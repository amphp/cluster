<?php

namespace Amp\Cluster\Internal;

use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\LogLevel;

final class IpcHandler extends AbstractProcessingHandler {
    /** @var \Amp\Cluster\Internal\IpcClient */
    private $client;

    public function __construct(IpcClient $client, string $level = LogLevel::INFO, bool $bubble = true) {
        parent::__construct($level, $bubble);
        $this->client = $client;
    }

    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  array $record
     *
     * @return void
     */
    protected function write(array $record) {
        $this->client->send("log", $record);
    }
}
