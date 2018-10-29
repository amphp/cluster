<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Cluster;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LogLevel;

final class IpcLogHandler extends AbstractProcessingHandler
{
    public function __construct(string $level = LogLevel::DEBUG, bool $bubble = false)
    {
        parent::__construct($level, $bubble);
    }

    /** @inheritdoc */
    protected function write(array $record)
    {
        Cluster::send(HandlerInterface::class, $record);
    }
}
