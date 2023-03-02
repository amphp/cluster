<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
use Amp\Delayed;
use Amp\Loop;
use Revolt\EventLoop;

$running = true;

Cluster::getChannel()->send('test-message');

EventLoop::queue(function () use (&$running) {
    Cluster::awaitTermination();
    $running = false;
});

while ($running) {
    \Amp\delay(100);
}
