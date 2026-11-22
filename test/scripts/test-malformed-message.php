<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;

Cluster::getChannel()->send('started');

// Send a value which is not a WorkerMessage on the internal channel to cause an unexpected exception in the watcher.
$ipcChannel = (static fn () => self::$cluster->ipcChannel)->bindTo(null, Cluster::class)();
$ipcChannel->send('malformed');

Cluster::awaitTermination();
