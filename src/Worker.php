<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\Cancellation;

interface Worker
{
    /**
     * @return int The worker id, equivalent to Cluster::getId() on the worker side
     */
    public function getId(): int;

    /**
     * Sends data to one specific worker.
     * Can be received in the worker via Cluster::getChannel()->receive().
     */
    public function send(mixed $data): void;

    /**
     * @param Cancellation|null $cancellation Token to request cancellation of waiting for shutdown. When cancelled, the worker is forcefully killed.
     * If null, the worker is killed immediately.
     */
    public function shutdown(?Cancellation $cancellation = null): void;
}
