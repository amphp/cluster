<?php declare(strict_types=1);

use Amp\Cluster\ServerSocketPipeProvider;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc\LocalIpcHub;
use function Amp\async;
use function Amp\Process\getSignalName;
use function Amp\trapSignal;

require dirname(__DIR__, 2) . "/vendor/autoload.php";

// This example creates child processes which listen on a server socket created and transferred from the
// parent process. Start the process and connect using `nc localhost 9337` to see the accepting PID.

$ipcHub = new LocalIpcHub();

echo "Making cluster server provider\n";
$provider = new ServerSocketPipeProvider();

/** @var list<ProcessContext> $processes */
$processes = [];

for ($i = 0; $i < 3; ++$i) {
    echo "Starting process\n";
    $process = ProcessContext::start($ipcHub, __DIR__ . '/children/server-consumer.php');

    printf("Piping output of process %d\n", $process->getPid());
    async(Amp\ByteStream\pipe(...), $process->getStdout(), Amp\ByteStream\getStdout())->ignore();

    $key = $ipcHub->generateKey();

    $process->send([
        'uri' => $ipcHub->getUri(),
        'key' => $key,
    ]);

    echo "Accepting client\n";
    $socket = $ipcHub->accept($key);

    echo "Providing servers for process\n";
    async($provider->provideFor(...), $socket);

    $processes[] = $process;
}

echo "Trapping signal to await process termination\n";
$signal = trapSignal([SIGTERM, SIGINT, SIGHUP]);

echo "Received signal " . (getSignalName($signal) ?? $signal) . ", shutting down\n";

foreach ($processes as $process) {
    $process->close();
}

$ipcHub->close();

echo "Done\n";
