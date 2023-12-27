<?php declare(strict_types=1);

namespace Amp\Cluster;

/**
 * @template TSend
 */
interface ClusterWorker
{
    /**
     * @return positive-int The worker ID, equivalent to {@see Cluster::getId()} on the worker side
     */
    public function getId(): int;

    /**
     * Sends data to one specific worker.
     * Can be received in the worker via {@see Cluster::getChannel()::receive()}.
     *
     * @param TSend $data
     */
    public function send(mixed $data): void;
}
