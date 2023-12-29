<?php declare(strict_types=1);

use Amp\ByteStream\ResourceStream;
use Amp\CancelledException;
use Amp\Cluster\ServerSocketPipeFactory;
use Amp\Parallel\Ipc;
use Amp\SignalCancellation;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceSocket;
use Amp\Sync\Channel;

return function (Channel $channel): void {
    $pid = getmypid();

    printf("Child started: %d\n", $pid);

    ['uri' => $uri, 'key' => $key] = $channel->receive();

    printf("Received %s for %s\n", base64_encode($key), $uri);

    $socket = Ipc\connect($uri, $key);
    if (!$socket instanceof ResourceSocket) {
        throw new \TypeError("Expected instance of " . ResourceStream::class);
    }

    $serverFactory = new ServerSocketPipeFactory($socket);

    $server = $serverFactory->listen(new InternetAddress('127.0.0.1', 9337));

    printf("Listening for connections on %s in PID %d\n", $server->getAddress()->toString(), $pid);

    $cancellation = new SignalCancellation([SIGTERM, SIGINT, SIGHUP]);

    try {
        while ($client = $server->accept($cancellation)) {
            printf(
                "Accepted %s on %s in PID %d\n",
                $client->getRemoteAddress()->toString(),
                $client->getLocalAddress()->toString(),
                $pid,
            );

            $client->close();
        }
    } catch (CancelledException) {
        // Signal received, exit.
    }
};
