<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\Cancellation;

/**
 * @template TReceive
 * @template TSend
 */
interface Worker
{
    /**
     * @return int The worker id, equivalent to {@see Cluster::getId()} on the worker side
     */
    public function getId(): int;

    /**
     * @param \Closure(TReceive):void $onMessage
     */
    public function onMessage(\Closure $onMessage): void;

    /**
     * Sends data to one specific worker.
     * Can be received in the worker via {@see Cluster::getChannel()}->receive().
     *
     * @param TSend $data
     */
    public function send(mixed $data): void;

    /**
     * @param Cancellation|null $cancellation Token to request cancellation of waiting for shutdown. When cancelled,
     * the worker is forcefully killed. If null, the worker is killed immediately.
     */
    public function shutdown(?Cancellation $cancellation = null): void;
}
