<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;

if (Cluster::getChannel()->receive() !== null) {
    throw new \Exception("Unexpected value received");
}

Cluster::getChannel()->send("Initiating shutdown");

Cluster::shutdown();
