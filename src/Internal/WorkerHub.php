<?php

namespace Amp\Cluster\Internal;

use Amp\Cluster\Watcher;
use Amp\DeferredFuture;
use Amp\Parallel\Context\ContextException;
use Amp\Socket\ResourceSocket;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Revolt\EventLoop;
use const Amp\Process\IS_WINDOWS;

/** @internal */
final class WorkerHub
{
    private const PROCESS_START_TIMEOUT = 5;
    private const KEY_RECEIVE_TIMEOUT = 5;

    /** @var resource|null */
    private $server;

    private string $uri;

    /** @var int[] */
    private array $keys = [];

    private string $watcher;

    /** @var DeferredFuture[] */
    private array $acceptor = [];

    private ?string $toUnlink = null;

    public function __construct()
    {
        if (IS_WINDOWS) {
            $this->uri = "tcp://127.0.0.1:0";
        } else {
            $suffix = \bin2hex(\random_bytes(10));
            $path = \sys_get_temp_dir() . "/amphp-cluster-ipc-" . $suffix . ".sock";
            $this->uri = "unix://" . $path;
            $this->toUnlink = $path;
        }

        $this->server = \stream_socket_server($this->uri, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);

        if (!$this->server) {
            throw new \RuntimeException(\sprintf("Could not create IPC server: (Errno: %d) %s", $errno, $errstr));
        }

        if (IS_WINDOWS) {
            $name = \stream_socket_get_name($this->server, false);
            $port = \substr($name, \strrpos($name, ":") + 1);
            $this->uri = "tcp://127.0.0.1:" . $port;
        }

        $keys = &$this->keys;
        $acceptor = &$this->acceptor;
        $this->watcher = EventLoop::onReadable($this->server, static function (string $watcher, $server) use (
            &$keys,
            &$acceptor
        ): void {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            if (!$client = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                return; // Accepting client failed.
            }

            $client = ResourceSocket::fromServerSocket($client);

            try {
                $timeout = new TimeoutCancellation(self::KEY_RECEIVE_TIMEOUT);

                $key = "";
                do {
                    if (null === $chunk = $client->read($timeout, Watcher::KEY_LENGTH - \strlen($key))) {
                        $key = null;
                        break;
                    }

                    $key .= $chunk;
                } while (\strlen($key) < Watcher::KEY_LENGTH);
            } catch (TimeoutException) {
                $client->close();
                return; // Ignore possible foreign connection attempt.
            }

            if (!\is_string($key) || !isset($keys[$key])) {
                $client->close();
                return; // Ignore possible foreign connection attempt.
            }

            $pid = $keys[$key];

            $deferred = $acceptor[$pid];
            unset($acceptor[$pid], $keys[$key]);
            $deferred->complete($client);
        });

        EventLoop::disable($this->watcher);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
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
        $this->acceptor[$pid] = new DeferredFuture;

        EventLoop::enable($this->watcher);

        try {
            $socket = $this->acceptor[$pid]->getFuture()->await(new TimeoutCancellation(self::PROCESS_START_TIMEOUT));
        } catch (TimeoutException $exception) {
            $key = \array_search($pid, $this->keys, true);
            \assert(\is_string($key), "Key for {$pid} not found");
            unset($this->acceptor[$pid], $this->keys[$key]);
            throw new ContextException("Starting the process timed out", 0, $exception);
        } finally {
            if (empty($this->acceptor)) {
                EventLoop::disable($this->watcher);
            }
        }

        return $socket;
    }
}
