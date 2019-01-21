<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Watcher;
use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Context\ContextException;
use Amp\Promise;
use Amp\Socket\ServerSocket;
use Amp\TimeoutException;
use function Amp\call;

final class WorkerHub
{
    const PROCESS_START_TIMEOUT = 5000;
    const KEY_RECEIVE_TIMEOUT = 1000;

    /** @var resource|null */
    private $server;

    /** @var string|null */
    private $uri;

    /** @var int[] */
    private $keys;

    /** @var string|null */
    private $watcher;

    /** @var Deferred[] */
    private $acceptor = [];

    public function __construct()
    {
        $isWindows = \strncasecmp(\PHP_OS, "WIN", 3) === 0;

        if ($isWindows) {
            $this->uri = "tcp://127.0.0.1:0";
        } else {
            $this->uri = "unix://" . \tempnam(\sys_get_temp_dir(), "amp-cluster-ipc-") . ".sock";
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
        $this->watcher = Loop::onReadable($this->server, static function (string $watcher, $server) use (&$keys, &$acceptor) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            if (!$client = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                return; // Accepting client failed.
            }

            $client = new ServerSocket($client);

            try {
                $received = yield Promise\timeout(call(function () use ($client) {
                    $key = "";
                    do {
                        if ((null === $chunk = yield $client->read())) {
                            return null;
                        }
                        $key .= $chunk;
                    } while (\strlen($key) < Watcher::KEY_LENGTH);
                    return $key;
                }), self::KEY_RECEIVE_TIMEOUT);
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
        });

        Loop::disable($this->watcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->watcher);
        \fclose($this->server);
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

    public function accept(int $pid): Promise
    {
        return call(function () use ($pid) {
            $this->acceptor[$pid] = new Deferred;

            Loop::enable($this->watcher);

            try {
                $socket = yield Promise\timeout($this->acceptor[$pid]->promise(), self::PROCESS_START_TIMEOUT);
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
        });
    }
}
