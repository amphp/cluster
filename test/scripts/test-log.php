<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\Cluster;
use Monolog\Logger;

$logger = new Logger('worker');
$logger->pushHandler(Cluster::createLogHandler());

$logger->info('First log record');
Cluster::getChannel()->send('logged');

$logger->info('Second log record');
Cluster::getChannel()->send('logged again');

Cluster::awaitTermination();
