<?php declare(strict_types=1);

namespace Amp\Cluster;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface as MonologProcessor;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Uses the provided processor ({@see PsrLogMessageProcessor} by default) to process the log record and then
 * removes the context and extra fields from the log record. This is useful for stripping context or extra data
 * which cannot be serialized to the parent process.
 */
final class ClusterLogSerializationProcessor implements MonologProcessor
{
    private const REPLACEMENTS = [
        'context' => [],
        'extra' => [],
    ];

    public function __construct(
        private readonly MonologProcessor $processor = new PsrLogMessageProcessor(),
    ) {
    }

    /**
     * @param array|LogRecord $record Array for Monolog v1.x or 2.x and {@see LogRecord} for v3.x.
     *
     * @psalm-suppress InvalidReturnType
     */
    #[\Override]
    public function __invoke(array|LogRecord $record)
    {
        /** @psalm-suppress PossiblyInvalidArgument */
        $processed = ($this->processor)($record);

        /** @psalm-suppress RedundantCondition */
        if ($processed instanceof LogRecord) {
            return $processed->with(...self::REPLACEMENTS);
        }

        /** @psalm-suppress InvalidReturnStatement, InvalidOperand */
        return [...$processed, ...self::REPLACEMENTS];
    }
}
