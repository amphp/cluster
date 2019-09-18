<?php

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
use Amp\Delayed;
use Amp\Loop;

Loop::run(function () {
    $running = true;

    Cluster::send('test-event', 'test-message');

    Cluster::onTerminate(function () use (&$running): void {
        $running = false;
    });

    while ($running) {
        yield new Delayed(100);
    }
});
