<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
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
