<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * @template-covariant TReceive
 * @template TSend
 */
final class ClusterWorkerMessage
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param ClusterWorker<TSend> $worker
     * @param TReceive $data
     */
    public function __construct(
        private readonly ClusterWorker $worker,
        private readonly mixed $data,
    ) {
    }

    /**
     * @return TReceive
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * @return ClusterWorker<TSend> Returns the worker which sent the message.
     */
    public function getWorker(): ClusterWorker
    {
        return $this->worker;
    }
}
