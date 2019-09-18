<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
use Amp\Loop;
use Amp\Socket\Server;

Loop::run(function () {
    $server = yield Cluster::listen('tcp://0.0.0.0:0');
    \assert($server instanceof Server);

    Cluster::send('port-number', $server->getAddress()->getPort());

    Cluster::onTerminate([$server, 'close']);

    yield $server->accept();
});
