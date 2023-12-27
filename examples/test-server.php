<?php declare(strict_types=1);

use Amp\Cluster\ServerSocketPipeProvider;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc\LocalIpcHub;
use function Amp\async;
use function Amp\trapSignal;

require dirname(__DIR__) . "/vendor/autoload.php";

$ipcHub = new LocalIpcHub();

echo "Making cluster server provider\n";
$provider = new ServerSocketPipeProvider();

$processes = [];

for ($i = 0; $i < 2; ++$i) {
    echo "Starting process\n";
    $process = ProcessContext::start($ipcHub, __DIR__ . '/test-client.php');

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
array_map(fn (ProcessContext $process) => $process->close(), $processes);
