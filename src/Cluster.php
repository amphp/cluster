<?php

namespace Amp\Cluster;

use Amp\ByteStream\InMemoryStream;
use Amp\CallableMaker;
use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync\ChannelledStream;
use Amp\Promise;
use Amp\Socket;
use Amp\Socket\Server;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use function Amp\call;

class Cluster {
    use CallableMaker;

    const SCRIPT_PATH = __DIR__ . '/Internal/cluster-runner.php';

    const CONNECT_TIMEOUT = 5000;

    const SHUTDOWN_TIMEOUT = 3000;

    /** @var \Amp\Socket\ClientSocket|null */
    private static $socket;

    /** @var \Amp\Parallel\Sync\Channel|null */
    private static $channel;

    /** @var string[]|null */
    private static $watchers;

    /** @var callable[]|null */
    private static $onClose = [];

    /** @var resource[] */
    private static $sockets = [];

    /** @var bool */
    private $running = false;

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

    /** @var \SplObjectStorage */
    private $workers;

    private static function init(Socket\ClientSocket $socket) {
        self::$socket = $socket;
        self::$channel = new ChannelledStream(new InMemoryStream, $socket); // Write-only channel.
    }

    private static function close() {
        if (self::$onClose === null) {
            return;
        }

        if (self::$socket !== null) {
            self::$socket->close();
        }

        if (self::$watchers !== null) {
            foreach (self::$watchers as $watcher) {
                Loop::cancel($watcher);
            }
        }

        $onClose = self::$onClose;
        self::$onClose = null;

        foreach ($onClose as $callable) {
            $callable();
        }
    }

    private static function send(array $message): Promise {
        \assert(self::isWorker(), \sprintf("Call to %s::%s() in parent", __CLASS__, __METHOD__));
        return self::$channel->send($message);
    }

    public static function isWorker(): bool {
        return self::$socket !== null;
    }

    public static function listen(
        string $uri,
        Socket\ServerListenContext $listenContext = null,
        Socket\ServerTlsContext $tlsContext = null
    ): Promise {
        // Add condition for systems where SO_REUSEPORT is supported to simply return from Socket\listen().

        if (!self::isWorker()) {
            $socket = self::bindSocket($uri);
            $socket = \socket_import_stream($socket);
            return new Success(self::listenOnSocket($socket, $listenContext, $tlsContext));
        }

        return call(function () use ($uri, $listenContext, $tlsContext) {
            yield self::send(["type" => "import-socket", "payload" => $uri]);

            $deferred = new Deferred;

            Loop::onReadable(self::$socket->getResource(), function ($watcherId) use ($deferred, $listenContext, $tlsContext) {
                Loop::cancel($watcherId);

                $socket = \socket_import_stream(self::$socket->getResource());

                $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)
                if (!\socket_recvmsg($socket, $data)) {
                    $deferred->fail(new \RuntimeException("Socket could not be received from parent process"));
                }

                $socket = $data["control"][0]["data"][0];

                $deferred->resolve(self::listenOnSocket($socket, $listenContext, $tlsContext));
            });

            return $deferred->promise();
        });
    }

    public static function onClose(callable $callable) {
        if (self::$onClose === null) {
            return;
        }

        if (self::$watchers === null) {
            self::$watchers = [];

            $callable = self::callableFromStaticMethod("close");

            self::$watchers[] = Loop::onSignal(\defined("SIGINT") ? \SIGINT : 2, $callable);
            self::$watchers[] = Loop::onSignal(\defined("SIGTERM") ? \SIGTERM : 15, $callable);

            foreach (self::$watchers as $watcher) {
                Loop::unreference($watcher);
            }
        }

        self::$onClose[] = $callable;
    }

    public function __construct($script, PsrLogger $logger = null) {
        if (self::isWorker()) {
            throw new \Error("A new cluster cannot be created from within a cluster worker");
        }

        $this->uri = "unix://" . \tempnam(\sys_get_temp_dir(), "amp-cluster-ipc-") . ".sock";
        $this->server = Socket\listen($this->uri);
        $this->logger = $logger ?? new NullLogger;

        $this->script = \array_merge(
            [self::SCRIPT_PATH, $this->uri],
            \is_array($script) ? \array_values(\array_map("strval", $script)) : [$script]
        );

        $this->workers = new \SplObjectStorage;
        $this->bind = self::callableFromStaticMethod("bindSocket");
    }

    public function __destruct() {
        if ($this->running) {
            $this->stop();
        }
    }

    public function start(int $count): Promise {
        if ($this->running) {
            throw new \Error("The cluster is already running");
        }

        if ($count <= 0) {
            throw new \Error("The number of workers must be greater than zero");
        }

        $this->running = true;

        return call(function () use ($count) {
            for ($i = 0; $i < $count; ++$i) {
                $process = new Process($this->script);
                $process->start();

                try {
                    /** @var \Amp\Socket\ClientSocket $socket */
                    $socket = yield Promise\timeout($this->server->accept(), self::CONNECT_TIMEOUT);
                } catch (\Throwable $exception) {
                    $this->stop();
                    throw $exception;
                }

                $worker = new Worker($socket, $this->logger, $this->bind);

                $this->workers->attach($worker, $process);

                $worker->run()->onResolve(function ($exception) {
                    // If the cluster has not stopped, restart worker?
                });
            }
        });
    }

    public function stop() {
        if (!$this->running) {
            throw new \Error("The cluster is not running");
        }

        foreach ($this->workers as $worker) {
            /** @var \Amp\Parallel\Context\Process $process */
            $process = $this->workers[$worker];

            $process->signal(\defined("SIGINT") ? \SIGINT : 2);

            $promise = Promise\timeout($process->join(), self::SHUTDOWN_TIMEOUT);
            $promise->onResolve(static function ($exception) use ($process) {
                if ($exception) {
                    $process->kill();
                }
            });
        }

        $this->workers = new \SplObjectStorage;
        $this->running = false;
    }

    /**
     * @param string $uri
     *
     * @return resource Stream socket server resource.
     */
    private static function bindSocket(string $uri) {
        if (isset(self::$sockets[$uri])) {
            return self::$sockets[$uri];
        }

        if (!\strncmp($uri, "unix://", 7)) {
            @\unlink(\substr($uri, 7));
        }

        // Do NOT use STREAM_SERVER_LISTEN here - we explicitly invoke \socket_listen() in our worker processes
        if (!$socket = \stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND)) {
            throw new \RuntimeException(\sprintf("Failed binding socket on %s: [Err# %s] %s", $uri, $errno, $errstr));
        }

        return self::$sockets[$uri] = $socket;
    }

    /**
     * @param resource $socket Socket resource (not a stream socket resource).
     * @param \Amp\Socket\ServerListenContext|null $listenContext
     * @param \Amp\Socket\ServerTlsContext|null $tlsContext
     *
     * @return \Amp\Socket\Server
     */
    private static function listenOnSocket(
        $socket,
        Socket\ServerListenContext $listenContext = null,
        Socket\ServerTlsContext $tlsContext = null
    ): Server {
        $listenContext = $listenContext ?? new Socket\ServerListenContext;

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
