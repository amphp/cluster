<?php

use Amp\Cluster\ClusterSocketServerProvider;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc\LocalIpcHub;
use function Amp\async;
use function Amp\trapSignal;

require \dirname(__DIR__) . "/vendor/autoload.php";

$ipcHub = new LocalIpcHub();

echo "Making cluster server provider\n";
$provider = new ClusterSocketServerProvider();

$processes = [];

for ($i = 0; $i < 2; ++$i) {
    echo "Starting process\n";
    $process = ProcessContext::start([__DIR__ . '/test-client.php', $ipcHub->getUri()]);

    echo "Piping output of process\n";
    async(Amp\ByteStream\pipe(...), $process->getStdout(), Amp\ByteStream\getStdout())->ignore();

    $key = $ipcHub->generateKey();

    $process->send($ipcHub->getUri());
    $process->send($key);

    echo "Accepting client\n";
    $socket = $ipcHub->accept($key);

    echo "Providing servers for process\n";
    $provider->provideFor($socket);

    $processes[] = $process;
}

echo "Trapping signal\n";
trapSignal(\SIGTERM);

$ipcHub->close();
\array_map(fn (ProcessContext $process) => $process->close(), $processes);