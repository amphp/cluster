<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

/** @internal */
final class WatcherMessage
{
    public function __construct(
        public readonly WatcherMessageType $type,
        public readonly mixed $data,
    ) {
    }
}
