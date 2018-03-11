<?php

namespace Amp\Cluster;

use Amp\Promise;

interface Bootable {
    /**
     * @param callable $bindSockets
     *
     * @return \Amp\Promise
     */
    public function start(callable $bindSockets): Promise;

    /**
     * @return \Amp\Promise
     */
    public function stop(): Promise;
}
