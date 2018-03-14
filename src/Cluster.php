<?php

namespace Amp\Cluster;

use Amp\CallableMaker;
use Amp\Parallel\Context\Process;
use Amp\Promise;
use Amp\Socket;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;
use Psr\Log\NullLogger;

class Cluster {
    use CallableMaker;

    const SCRIPT_PATH = __DIR__ . '/Internal/cluster-runner.php';

    const CONNECT_TIMEOUT = 1000;

    /** @var string[] */
    private $script;

    /** @var string Socket server URI */
    private $uri;

    /** @var \Amp\Socket\Server */
    private $server;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var callable */
    private $bind;

    /** @var resource[] */
    private $sockets = [];

    /** @var \SplObjectStorage */
    private $workers;

    public function __construct($script, PsrLogger $logger = null) {
        $this->uri = "unix://" . \tempnam(\sys_get_temp_dir(), "amp-cluster-ipc-") . ".sock";
        $this->server = Socket\listen($this->uri);
        $this->logger = $logger ?? new NullLogger;

        $this->script = \is_array($script) ? \array_values(\array_map("strval", $script)) : [$script];

        $this->workers = new \SplObjectStorage;
        $this->bind = $this->callableFromInstanceMethod("bind");
    }

    public function __destruct() {
        foreach ($this->workers as $worker) {
            $process = $this->workers[$worker];
            $process->send(null);
            $process->join();
        }
    }

    public function start(): Promise {
        return call(function () {
            $command = \array_merge([self::SCRIPT_PATH, $this->uri], $this->script);

            $process = new Process($command);

            $process->start();

            try {
                /** @var \Amp\Socket\ClientSocket $socket */
                $socket = yield Promise\timeout($this->server->accept(), 1000);
            } catch (\Throwable $exception) {
                $process->kill();
                throw $exception;
            }

            $worker = new Worker($socket, $this->logger, $this->bind);

            $this->workers->attach($worker, $process);

            return $worker;
        });
    }

    private function bind(string $uri) {
        if (isset($this->sockets[$uri])) {
            return $this->sockets[$uri];
        }

        if (!\strncmp($uri, "unix://", 7)) {
            @\unlink(\substr($uri, 7));
        }

        // Do NOT use STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
        if (!$socket = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND)) {
            throw new \RuntimeException(\sprintf("Failed binding socket on %s: [Err# %s] %s", $uri, $errno, $errstr));
        }

        return $this->sockets[$uri] = $socket;
    }
}
