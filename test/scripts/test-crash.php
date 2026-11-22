<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;

Cluster::getChannel()->send('started');

throw new \Exception('Worker crash');
