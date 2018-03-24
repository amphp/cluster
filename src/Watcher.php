<?php

namespace Amp\Cluster;

use Amp\CallableMaker;
use Amp\Emitter;
use Amp\Iterator;
use Amp\MultiReasonException;
use Amp\Parallel\Context\Process;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use function Amp\call;

final class Watcher {
    use CallableMaker;

    /** @var resource[] */
    private $sockets = [];

    /** @var bool */
    private $running = false;

    /** @var string[] */
    private $script;

    /** @var Logger */
    private $logger;

    /** @var HandlerInterface */
    private $logHandler;

    /** @var string Socket server URI */
    private $uri;

    /** @var Server */
    private $server;

    /** @var Emitter */
    private $emitter;

    /** @var callable */
    private $bind;

    /** @var \SplObjectStorage */
    private $workers;

    /**
     * @param string|string[]  $script Script path and optional arguments.
     * @param HandlerInterface $logHandler
     */
    public function __construct($script, HandlerInterface $logHandler) {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        if (!canReusePort() && !\extension_loaded("sockets")) {
            throw new \Error("The sockets extension is required to create clusters on this system");
        }

        $this->logHandler = $logHandler ?? new NullHandler;
        $this->logger = new Logger('cluster');
        $this->logger->pushHandler($this->logHandler);

        $this->uri = "unix://" . \tempnam(\sys_get_temp_dir(), "amp-cluster-ipc-") . ".sock";

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php', $this->uri],
            \is_array($script) ? \array_values(\array_map("strval", $script)) : [$script]
        );

        $this->workers = new \SplObjectStorage;
        $this->bind = $this->callableFromInstanceMethod("bindSocket");
    }

    public function __destruct() {
        if ($this->running) {
            $this->stop();
        }
    }

    /**
     * @param int $count Number of cluster workers to spawn.
     *
     * @return Promise Succeeded when the cluster has started.
     */
    public function start(int $count): Promise {
        if ($this->running) {
            throw new \Error("The cluster is already running");
        }

        $this->emitter = new Emitter;
        $this->server = Socket\listen($this->uri);

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->running = true;

        return call(function () use ($count) {
            for ($i = 0; $i < $count; ++$i) {
                $process = new Process($this->script);
                $process->start();

                try {
                    /** @var Socket\ClientSocket $socket */
                    $socket = yield Promise\timeout($this->server->accept(), 5000);
                } catch (\Throwable $exception) {
                    if ($process->isRunning()) {
                        $process->kill();
                    }

                    yield $this->stop();
                    throw $exception;
                }

                $worker = new Internal\IpcParent($process, $socket, $this->logHandler, $this->emitter, $this->bind);
                $this->workers->attach($worker, [$process, $promise = $worker->run()]);
                $promise->onResolve(function ($error) {
                    if ($error) {
                        $this->logger->error((string) $error);
                    }
                });
            }
        });
    }

    /**
     * Stops the cluster.
     */
    public function stop(): Promise {
        if (!$this->running) {
            return new Success;
        }

        $this->running = false;

        return call(function () {
            $promises = [];

            /** @var Internal\IpcParent $worker */
            foreach ($this->workers as $worker) {
                $promises[] = call(function () use ($worker) {
                    /** @var Process $process */
                    list($process, $promise) = $this->workers[$worker];

                    if (!$process->isRunning()) {
                        return;
                    }

                    try {
                        yield $process->send(null);
                        yield Promise\timeout($promise, 5000);
                    } catch (\Throwable $exception) {
                        if ($process->isRunning()) {
                            $process->kill();
                        }

                        throw $exception;
                    }
                });
            }

            list($exceptions) = yield Promise\any($promises);

            $this->emitter->complete();

            $this->server->close();

            $this->workers = new \SplObjectStorage;

            if (!empty($exceptions)) {
                throw new MultiReasonException($exceptions);
            }
        });
    }

    /**
     * Broadcast data to all workers, triggering any callbacks registered with Cluster::onMessage().
     *
     * @param mixed $data
     *
     * @return Promise Resolved once data has been sent to all workers.
     */
    public function broadcast($data): Promise {
        $promises = [];
        /** @var Internal\IpcParent $worker */
        foreach ($this->workers as $worker) {
            $promises[] = $worker->send($data);
        }
        return Promise\all($promises);
    }

    /**
     * Returns an iterator of messages received from any worker.
     *
     * @return Iterator
     *
     * @throws \Error If the cluster has not been started.
     */
    public function iterate(): Iterator {
        if (!$this->emitter) {
            throw new \Error("The cluster has not been started");
        }

        return $this->emitter->iterate();
    }

    /**
     * @param string $uri
     *
     * @return resource Stream socket server resource.
     */
    private function bindSocket(string $uri) {
        if (isset($this->sockets[$uri])) {
            return $this->sockets[$uri];
        }

        if (!\strncmp($uri, "unix://", 7)) {
            @\unlink(\substr($uri, 7));
        }

        $context = \stream_context_create([
            "socket" => [
                "so_reuseaddr" => \stripos(PHP_OS, "WIN") === 0, // SO_REUSEADDR has SO_REUSEPORT semantics on Windows
                "so_reuseport" => canReusePort(),
                "ipv6_v6only" => true,
            ],
        ]);

        // Do NOT use STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
        if (!$socket = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context)) {
            throw new \RuntimeException(\sprintf("Failed binding socket on %s: [Err# %s] %s", $uri, $errno, $errstr));
        }

        return $this->sockets[$uri] = $socket;
    }
}
