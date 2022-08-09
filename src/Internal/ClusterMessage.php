<?php

namespace Amp\Cluster\Internal;

final class ClusterMessage
{
    public function __construct(
        public readonly ClusterMessageType $type,
        public readonly mixed $data,
    ) {
    }
}
