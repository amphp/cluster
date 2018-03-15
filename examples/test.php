<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Cluster\Cluster;
use function Amp\Cluster\countCpuCores;

Amp\Loop::run(function () {
    $cluster = new Cluster(__DIR__ . "/test-worker.php");

    yield $cluster->start(2);

    Cluster::onClose(function () use ($cluster) {
        $cluster->stop();
    });
});
