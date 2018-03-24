<?php

namespace Amp\Cluster;

use Amp\ByteStream\ResourceOutputStream;
use Amp\CallableMaker;
use Amp\Cluster\Internal\IpcClient;
use Amp\Emitter;
use Amp\Iterator;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\MultiReasonException;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Monolog\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Psr\Log\LogLevel;
use function Amp\asyncCall;
use function Amp\call;

class Cluster {
    use CallableMaker;

    const SCRIPT_PATH = __DIR__ . '/Internal/cluster-runner.php';

    const CONNECT_TIMEOUT = 5000;

    const SHUTDOWN_TIMEOUT = 3000;

    /** @var IpcClient */
    private static $client;

    /** @var callable[]|null */
    private static $onClose = [];

    /** @var callable[] */
    private static $onMessage = [];

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
     * @param Channel             $channel
     * @param Socket\ClientSocket $socket
     */
    private static function init(Channel $channel, Socket\ClientSocket $socket) {
        self::$client = new IpcClient($channel, $socket, self::callableFromStaticMethod("onReceivedMessage"));
        self::$client->run()->onResolve(self::callableFromStaticMethod("terminate"));
    }

    /**
     * Invokes any termination callbacks.
     *
     * @return Promise|null
     */
    private static function terminate() { /* ?Promise */
        if (self::$onClose === null) {
            return null;
        }

        if (self::$client !== null) {
            self::$client = null;
        }

        $onClose = self::$onClose;
        self::$onClose = null;

        $promises = [];
        foreach ($onClose as $callable) {
            $promises[] = call($callable);
        }
        return Promise\all($promises);
    }

    /**
     * @return bool
     */
    public static function isWorker(): bool {
        return self::$client !== null;
    }

    /**
     * @param string                          $uri
     * @param Socket\ServerListenContext|null $listenContext
     * @param Socket\ServerTlsContext|null    $tlsContext
     *
     * @return Promise
     */
    public static function listen(
        string $uri,
        Socket\ServerListenContext $listenContext = null,
        Socket\ServerTlsContext $tlsContext = null
    ): Promise {
        $listenContext = $listenContext ?? new Socket\ServerListenContext;

        return call(function () use ($uri, $listenContext, $tlsContext) {
            if (!self::isWorker()) {
                return Socket\listen($uri, $listenContext, $tlsContext);
            }

            if (canReusePort()) {
                $position = \strrpos($uri, ":");
                $port = $position ? (int) \substr($uri, $position) : 0;

                if ($port === 0) {
                    $uri = yield self::$client->selectPort($uri);
                }

                $listenContext = $listenContext->withReusePort();
                return Socket\listen($uri, $listenContext, $tlsContext);
            }

            $socket = yield self::$client->importSocket($uri);
            return self::listenOnBoundSocket($socket, $listenContext, $tlsContext);
        });
    }

    /**
     * @param HandlerInterface|null $handler Handler used if not running as a cluster. A default stream handler is
     *     created otherwise.
     * @param string                $logLevel Log level for the IPC handler and for the default handler if no handler
     *     is given.
     * @param bool                  $bubble Bubble flag for the IPC handler and for the default handler if no handler
     *     is given.
     *
     * @return HandlerInterface
     */
    public static function getLogHandler(
        HandlerInterface $handler = null,
        string $logLevel = LogLevel::DEBUG,
        bool $bubble = false
    ): HandlerInterface {
        if (!self::isWorker()) {
            return $handler ?? (function () use ($logLevel, $bubble) {
                $handler = new StreamHandler(new ResourceOutputStream(\STDOUT), $logLevel, $bubble);
                $handler->setFormatter(new ConsoleFormatter);

                return $handler;
            })();
        }

        return new Internal\IpcLogHandler(self::$client, $logLevel, $bubble);
    }

    /**
     * Internal callback triggered when a message is received from the parent.
     *
     * @param mixed $data
     */
    private static function onReceivedMessage($data) {
        foreach (self::$onMessage as $callback) {
            asyncCall($callback, $data);
        }
    }

    /**
     * Attaches a callback to be invoked when a message is received from the parent process.
     *
     * @param callable $callback
     */
    public static function onMessage(callable $callback) {
        self::$onMessage[] = $callback;
    }

    /**
     * @param mixed $data Send data to the parent.
     *
     * @return Promise
     */
    public static function send($data): Promise {
        if (!self::isWorker()) {
            return new Success; // Ignore sent messages when running as a standalone process.
        }

        return self::$client->send("data", $data);
    }

    /**
     * @param callable $callable Callable to invoke to shutdown the process.
     */
    public static function onTerminate(callable $callable) {
        if (!self::isWorker()) {
            return;
        }

        if (self::$onClose === null) {
            return;
        }

        self::$onClose[] = $callable;
    }

    /**
     * @param string|string[]  $script Script path and optional arguments.
     * @param HandlerInterface $logHandler
     */
    public function __construct($script, HandlerInterface $logHandler) {
        if (self::isWorker()) {
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
            [self::SCRIPT_PATH, $this->uri],
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
                    $socket = yield Promise\timeout($this->server->accept(), self::CONNECT_TIMEOUT);
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
                        yield Promise\timeout($promise, self::SHUTDOWN_TIMEOUT);
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
     * @return \Amp\Iterator
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

    /**
     * @param resource                        $socket Socket resource (not a stream socket resource).
     * @param Socket\ServerListenContext|null $listenContext
     * @param Socket\ServerTlsContext|null    $tlsContext
     *
     * @return Server
     */
    private static function listenOnBoundSocket(
        $socket,
        Socket\ServerListenContext $listenContext = null,
        Socket\ServerTlsContext $tlsContext = null
    ): Server {
        if ($tlsContext) {
            $context = \array_merge(
                $listenContext->toStreamContextArray(),
                $tlsContext->toStreamContextArray()
            );
        } else {
            $context = $listenContext->toStreamContextArray();
        }

        \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

        $socket = \socket_export_stream($socket);
        \stream_context_set_option($socket, $context); // put eventual options like ssl back (per worker)

        return new Server($socket);
    }
}
