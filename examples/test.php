<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;
use Amp\Cluster\Console;
use Amp\Cluster\ConsoleLogger;
use Amp\Loop;
use function Amp\Cluster\countCpuCores;

Loop::run(function () {
    $logger = new ConsoleLogger(new Console);

    $cluster = new Cluster(__DIR__ . "/test-worker.php");

    yield $cluster->start(2);
    //yield $cluster->start(countCpuCores());

    Cluster::onTerminate(function () use ($cluster) {
        $cluster->stop();
    });

    $iterator = $cluster->iterate();

    while (yield $iterator->advance()) {
        $logger->info($iterator->getCurrent());
    }
});
