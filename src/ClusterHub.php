<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Socket\ResourceSocket;

interface ClusterHub extends IpcHub
{
    /**
     * Overrides the return type, requiring an instance of {@see ResourceSocket}.
     */
    public function accept(string $key, ?Cancellation $cancellation = null): ResourceSocket;
}
