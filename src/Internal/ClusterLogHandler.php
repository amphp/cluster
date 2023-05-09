<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\Sync\Channel;
use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\LogLevel;

final class ClusterLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Channel $channel,
        string $level = LogLevel::DEBUG,
        bool $bubble = false,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        $this->channel->send(new ClusterMessage(ClusterMessageType::Log, $record));
    }
}
