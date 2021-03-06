<?php

namespace Amp\Cluster\Internal;

use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\LogLevel;
use function Amp\Promise\rethrow;

/** @internal */
final class IpcLogHandler extends AbstractProcessingHandler
{
    /** @var IpcClient */
    private $client;

    public function __construct(IpcClient $client, string $level = LogLevel::DEBUG, bool $bubble = false)
    {
        parent::__construct($level, $bubble);
        $this->client = $client;
    }

    /** @inheritdoc */
    protected function write(array $record): void
    {
        rethrow($this->client->log($record));
    }
}
