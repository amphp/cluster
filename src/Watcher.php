<?php

/** @noinspection PhpUndefinedClassInspection CallableMaker */

namespace Amp\Cluster;

use Amp\CallableMaker;
use Amp\MultiReasonException;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\Process;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\asyncCall;
use function Amp\call;

final class Watcher {
    use CallableMaker;

    /** @var resource[] */
    private $sockets = [];

    /** @var bool */
    private $running = false;

    /** @var string[] */
    private $script;

    /** @var PsrLogger */
    private $logger;

    /** @var string Socket server URI */
    private $uri;

    /** @var Server */
    private $server;

    /** @var callable */
    private $bind;

    /** @var \SplObjectStorage */
    private $workers;

    /** @var callable[][] */
    private $onMessage = [];

    /**
     * @param string|string[]  $script Script path and optional arguments.
     * @param PsrLogger $logger
     */
    public function __construct($script, PsrLogger $logger) {
        if (Cluster::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        if (!canReusePort() && !\extension_loaded("sockets")) {
            throw new \Error("The sockets extension is required to create clusters on this system");
        }

        $this->logger = $logger;
        $this->uri = "unix://" . \tempnam(\sys_get_temp_dir(), "amp-cluster-ipc-") . ".sock";

        $this->script = \array_merge(
            [__DIR__ . '/Internal/cluster-runner.php', $this->uri],
            \is_array($script) ? \array_values(\array_map("\\strval", $script)) : [(string) $script]
        );

        $this->workers = new \SplObjectStorage;

        /** @noinspection PhpDeprecationInspection */
        $this->bind = $this->callableFromInstanceMethod("bindSocket");
    }

    public function __destruct() {
        if ($this->running) {
            $this->stop();
        }
    }

    /**
     * Attaches a callback to be invoked when a message is received from a worker process.
     *
     * @param string   $event
     * @param callable $callback
     */
    public function onMessage(string $event, callable $callback) {
        $this->onMessage[$event][] = $callback;
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

        $this->server = Socket\listen($this->uri);

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->running = true;

        return call(function () use ($count) {
            for ($i = 0; $i < $count; ++$i) {
                yield $this->startWorker();
            }
        });
    }

    private function startWorker(): Promise {
        return call(function () {
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
            
            if (!$socket) {
                if ($process->isRunning()) {
                    $process->kill();
                }
                return;
            }

            $worker = new Internal\IpcParent($process, $socket, $this->bind, function (string $event, $data) {
                foreach ($this->onMessage[$event] ?? [] as $callback) {
                    asyncCall($callback, $data);
                }
            });

            $this->workers->attach($worker, [$process, $promise = $worker->run()]);
            $promise->onResolve(function ($error) {
                if ($error) {
                    if ($error instanceof ContextException) {
                        $this->logger->error("Worker died unexpectedly" . ($this->running ? ", restarting..." : "."));
                    } else {
                        $this->logger->error((string) $error);
                    }
                } else {
                    $this->logger->info("Worker terminated cleanly" . ($this->running ? ", restarting..." : "."));
                }

                if ($this->running) {
                    Promise\rethrow($this->startWorker());
                }
            });
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
                        // FIXME: With this early return graceful shutdown works unreliable,
                        // this is because isRunning() returns false while the process is still running.
                        // return;
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

    /* @noinspection PhpUnusedPrivateMethodInspection */
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
