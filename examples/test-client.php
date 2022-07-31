<?php

use Amp\CancelledException;
use Amp\Cluster\ClusterSocketServerFactory;
use Amp\Parallel\Ipc;
use Amp\SignalCancellation;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Sync\Channel;

/**
 * @param Channel<string, SocketAddress> $channel
 */
return function (Channel $channel): void {
    echo "Child started\n";

    $uri = $channel->receive();
    $key = $channel->receive();

    echo sprintf("Received %s and %s\n", $uri, base64_encode($key));

    $socket = Ipc\connect($uri, $key);

    $clusterServerFactory = new ClusterSocketServerFactory($socket);

    $server = $clusterServerFactory->listen(new InternetAddress('127.0.0.1', 9337));

    echo $server->getAddress()->toString(), PHP_EOL;

    $myPid = getmypid();

    $cancellation = new SignalCancellation([\SIGTERM, SIGINT, SIGHUP]);

    try {
        while ($client = $server->accept($cancellation)) {
            echo "Accepted {$client->getRemoteAddress()->toString()} on {$client->getLocalAddress()->toString()} in PID {$myPid}\n";
            $client->close();
        }
    } catch (CancelledException) {
        // Signal received, exit.
    }
};
