<?php

namespace Amp\Cluster\Internal;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket\ClientSocket;
use Amp\Success;
use function Amp\asyncCall;
use function Amp\call;

final class IpcClient {
    /** @var resource */
    private $socket;

    /** @var string|null */
    private $importWatcher;

    /** @var Channel */
    private $channel;

    /** @var \SplQueue */
    private $pendingResponses;

    public function __construct(Channel $channel, ClientSocket $socket) {
        $this->socket = $socket->getResource();
        $this->channel = $channel;
        $this->pendingResponses = new \SplQueue;

        asyncCall(function () {
            while (null !== $message = yield $this->channel->receive()) {
                if ($message["type"] === "ping") {
                    $this->channel->send(["type" => "pong", "payload" => null]);
                } elseif ($message["type"] === "import-socket") {
                    $this->importSocket();
                }
            }
        });
    }

    public function __destruct() {
        if ($this->importWatcher !== null) {
            Loop::cancel($this->importWatcher);
        }
    }

    public function send(string $command, $data): Promise {
        return call(function () use ($command, $data) {
            if ($command !== "data") {
                $deferred = new Deferred;
                $this->pendingResponses->push($deferred);
            }

            yield $this->channel->send([
                "type" => $command,
                "payload" => $data,
            ]);

            if ($command !== "data") {
                return $deferred->promise();
            }

            return new Success;
        });
    }

    private function importSocket() {
        if ($this->importWatcher === null) {
            $this->importWatcher = Loop::onReadable($this->socket, function ($watcher) {
                if ($this->pendingResponses->isEmpty()) {
                    throw new \RuntimeException("Unexpected import-socket message.");
                }

                /** @var Deferred $pendingSocketImport */
                $pendingSocketImport = $this->pendingResponses->pop();

                $socket = \socket_import_stream($this->socket);
                $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)

                \error_clear_last();
                if (!@\socket_recvmsg($socket, $data)) {
                    $error = \error_get_last()["message"] ?? "Unknown error";
                    $pendingSocketImport->fail(new \RuntimeException("Could not transfer socket: " . $error));
                } else {
                    $socket = $data["control"][0]["data"][0];
                    $pendingSocketImport->resolve($socket);
                }

                if ($this->pendingResponses->isEmpty()) {
                    Loop::disable($watcher);
                }
            });
        }

        Loop::enable($this->importWatcher);
    }
}
