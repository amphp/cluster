<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;

Cluster::getChannel()->send('started');

// Blocks the event loop, so pings from the watcher are not answered.
\sleep(3);

Cluster::awaitTermination();
