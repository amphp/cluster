<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;
use Amp\Cluster\Console;
use Amp\Cluster\ConsoleLogger;

Amp\Loop::run(function () {
    $cluster = new Cluster(__DIR__ . "/test-worker.php", new ConsoleLogger(new Console));

    $worker1 = yield $cluster->start();
    $worker2 = yield $cluster->start();

    try {
        yield [$worker1->run(), $worker2->run()];

    } catch (\Throwable $exception) {
        echo $exception;
    }
});
