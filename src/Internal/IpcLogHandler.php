<?php

namespace Amp\Cluster\Internal;

use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\LogLevel;

/** @internal */
final class IpcLogHandler extends AbstractProcessingHandler
{
    private IpcClient $client;

    public function __construct(IpcClient $client, string $level = LogLevel::DEBUG, bool $bubble = false)
    {
        parent::__construct($level, $bubble);
        $this->client = $client;
    }

    /** @inheritdoc */
    protected function write(array $record): void
    {
        $this->client->log($record);
    }
}
