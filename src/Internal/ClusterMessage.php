<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

/** @internal */
final class ClusterMessage
{
    public function __construct(
        public readonly ClusterMessageType $type,
        public readonly mixed $data,
    ) {
    }
}
