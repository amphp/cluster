<?php

namespace Amp\Cluster\Internal;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket\ClientSocket;
use function Amp\call;

final class IpcClient {
    /** @var resource */
    private $socket;

    /** @var string|null */
    private $importWatcher;

    /** @var Channel */
    private $channel;

    /** @var callable */
    private $onData;

    /** @var \SplQueue */
    private $pendingResponses;

    public function __construct(Channel $channel, ClientSocket $socket, callable $onData) {
        $this->socket = $socket = $socket->getResource();
        $this->channel = $channel;
        $this->onData = $onData;
        $this->pendingResponses = $pendingResponses = new \SplQueue;

        $this->importWatcher = Loop::onReadable($this->socket, static function ($watcher) use ($socket, $pendingResponses) {
            if ($pendingResponses->isEmpty()) {
                throw new \RuntimeException("Unexpected import-socket message.");
            }

            /** @var Deferred $pendingSocketImport */
            $pendingSocketImport = $pendingResponses->shift();

            $socket = \socket_import_stream($socket);
            $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)

            \error_clear_last();
            if (!@\socket_recvmsg($socket, $data)) {
                $error = \error_get_last()["message"] ?? "Unknown error";
                $pendingSocketImport->fail(new \RuntimeException("Could not transfer socket: " . $error));
            } else {
                $socket = $data["control"][0]["data"][0];
                $pendingSocketImport->resolve($socket);
            }

            if ($pendingResponses->isEmpty()) {
                Loop::disable($watcher);
            }
        });

        Loop::disable($this->importWatcher);
    }

    public function __destruct() {
        if ($this->importWatcher !== null) {
            Loop::cancel($this->importWatcher);
        }
    }

    public function run(): Promise {
        return call(function () {
            while (null !== $message = yield $this->channel->receive()) {
                yield from $this->handleMessage($message);
            }

            yield $this->channel->send(null);
        });
    }

    private function handleMessage(array $message): \Generator {
        \assert(\array_key_exists("type", $message) && \array_key_exists("payload", $message));

        switch ($message["type"]) {
            case "ping":
                yield $this->channel->send(["type" => "pong", "payload" => null]);
                break;

            case "import-socket":
                Loop::enable($this->importWatcher);
                break;

            case "select-port":
                if ($this->pendingResponses->isEmpty()) {
                    throw new \RuntimeException("Unexpected select-port message.");
                }

                $deferred = $this->pendingResponses->shift();
                $deferred->resolve($message["payload"]);
                break;

            case "data":
                ($this->onData)($message["payload"]);
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }

    public function importSocket(string $uri): Promise {
        return call(function () use ($uri) {
            $deferred = new Deferred;
            $this->pendingResponses->push($deferred);

            yield $this->send("import-socket", $uri);

            return yield $deferred->promise();
        });
    }

    public function selectPort(string $uri): Promise {
        return call(function () use ($uri) {
            $deferred = new Deferred;
            $this->pendingResponses->push($deferred);

            yield $this->send("select-port", $uri);

            return yield $deferred->promise();
        });
    }

    public function send(string $command, $data): Promise {
        return $this->channel->send([
            "type" => $command,
            "payload" => $data,
        ]);
    }
}
