<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * @template-covariant TReceive
 * @template TSend
 */
final class WorkerMessage
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param Worker<TSend> $worker
     * @param TReceive $data
     */
    public function __construct(
        private readonly Worker $worker,
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
     * @return Worker<TSend> Returns the worker which sent the message.
     */
    public function getWorker(): Worker
    {
        return $this->worker;
    }
}