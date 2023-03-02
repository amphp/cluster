<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
use Amp\Socket\ServerSocket;
use Revolt\EventLoop;

$server = Cluster::getServerSocketFactory()->listen('tcp://0.0.0.0:0');
\assert($server instanceof ServerSocket);

Cluster::getChannel()->send($server->getAddress()->getPort());

EventLoop::queue(function () use ($server) {
    Cluster::awaitTermination();
    $server->close();
});

$server->accept();
