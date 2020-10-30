<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Watcher;
use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Context\ContextException;
use Amp\Promise;
use Amp\Socket\ResourceSocket;
use Amp\TimeoutException;
use function Amp\async;
use function Amp\asyncCallable;
use function Amp\await;

/** @internal */
final class WorkerHub
{
    private const PROCESS_START_TIMEOUT = 5000;
    private const KEY_RECEIVE_TIMEOUT = 1000;

    /** @var resource|null */
    private $server;

    private string $uri;

    /** @var int[] */
    private array $keys = [];

    private string $watcher;

    /** @var Deferred[] */
    private array $acceptor = [];

    private ?string $toUnlink = null;

    public function __construct()
    {
        $isWindows = \strncasecmp(\PHP_OS, "WIN", 3) === 0;

        if ($isWindows) {
            $this->uri = "tcp://127.0.0.1:0";
        } else {
            $suffix = \bin2hex(\random_bytes(10));
            $path = \sys_get_temp_dir() . "/amp-cluster-ipc-" . $suffix . ".sock";
            $this->uri = "unix://" . $path;
            $this->toUnlink = $path;
        }

        $this->server = \stream_socket_server($this->uri, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);

        if (!$this->server) {
            throw new \RuntimeException(\sprintf("Could not create IPC server: (Errno: %d) %s", $errno, $errstr));
        }

        if ($isWindows) {
            $name = \stream_socket_get_name($this->server, false);
            $port = \substr($name, \strrpos($name, ":") + 1);
            $this->uri = "tcp://127.0.0.1:" . $port;
        }

        $keys = &$this->keys;
        $acceptor = &$this->acceptor;
        $this->watcher = Loop::onReadable($this->server, asyncCallable(static function (string $watcher, $server) use (
            &$keys,
            &$acceptor
        ): void {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            if (!$client = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                return; // Accepting client failed.
            }

            $client = ResourceSocket::fromServerSocket($client);

            try {
                $received = await(Promise\timeout(async(static function () use ($client): ?string {
                    $key = "";
                    do {
                        if ((null === $chunk = $client->read())) {
                            return null;
                        }
                        $key .= $chunk;
                    } while (\strlen($key) < Watcher::KEY_LENGTH);
                    return $key;
                }), self::KEY_RECEIVE_TIMEOUT));
            } catch (TimeoutException $exception) {
                $client->close();
                return; // Ignore possible foreign connection attempt.
            }

            if (!\is_string($received) || !isset($keys[$received])) {
                $client->close();
                return; // Ignore possible foreign connection attempt.
            }

            $pid = $keys[$received];

            $deferred = $acceptor[$pid];
            unset($acceptor[$pid], $keys[$received]);
            $deferred->resolve($client);
        }));

        Loop::disable($this->watcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->watcher);
        \fclose($this->server);
        if ($this->toUnlink !== null) {
            @\unlink($this->toUnlink);
        }
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function generateKey(int $pid, int $length): string
    {
        $key = \random_bytes($length);
        $this->keys[$key] = $pid;
        return $key;
    }

    public function accept(int $pid): ResourceSocket
    {
        $this->acceptor[$pid] = new Deferred;

        Loop::enable($this->watcher);

        try {
            $socket = await(Promise\timeout($this->acceptor[$pid]->promise(), self::PROCESS_START_TIMEOUT));
        } catch (TimeoutException $exception) {
            $key = \array_search($pid, $this->keys, true);
            \assert(\is_string($key), "Key for {$pid} not found");
            unset($this->acceptor[$pid], $this->keys[$key]);
            throw new ContextException("Starting the process timed out", 0, $exception);
        } finally {
            if (empty($this->acceptor)) {
                Loop::disable($this->watcher);
            }
        }

        return $socket;
    }
}
