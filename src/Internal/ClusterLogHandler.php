<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sync\Channel;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LogLevel;

/** @internal */
final class ClusterLogHandler extends AbstractProcessingHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param value-of<Level::NAMES>|value-of<Level::VALUES>|Level|LogLevel::* $level
     *
     * @psalm-suppress MismatchingDocblockParamType, PossiblyInvalidArgument, UnresolvableConstant
     */
    public function __construct(
        private readonly Channel $channel,
        int|string|Level $level = LogLevel::DEBUG,
        bool $bubble = false,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * @param array|LogRecord $record Array for Monolog v1.x or 2.x and {@see LogRecord} for v3.x.
     */
    protected function write(array|LogRecord $record): void
    {
        $this->channel->send(new WorkerMessage(WorkerMessageType::Log, $record));
    }
}
