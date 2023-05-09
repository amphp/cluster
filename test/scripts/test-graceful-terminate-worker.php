<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;

Cluster::getChannel()->send("Active");

Cluster::awaitTermination();

Cluster::getChannel()->send("Adios");
