<?php

namespace Amp\Cluster;

use Amp\ByteStream\OutputBuffer;
use Amp\Emitter;
use Amp\Parallel\Sync\ChannelledStream;
use Amp\Promise;
use Amp\Socket\Socket;
use function Amp\call;

class Worker {
    /** @var \Amp\Socket\Socket */
    private $socket;

    /** @var \Amp\Parallel\Sync\ChannelledStream */
    private $channel;

    /** @var \Amp\Emitter */
    private $emitter;

    /** @var callable */
    private $bind;

    public function __construct(Socket $socket, Emitter $emitter, callable $bind) {
        $this->socket = $socket;
        $this->emitter = $emitter;
        $this->channel = new ChannelledStream($this->socket, new OutputBuffer); // Channel is read-only.
        $this->bind = $bind;
    }

    public function run(): Promise {
        return call(function () {
            while (null !== $message = yield $this->channel->receive()) {
                $this->handleMessage($message);
            }

            $this->socket->close();
            $this->socket = null;
        });
    }

    private function handleMessage(array $message) {
        if (!isset($message["type"], $message["payload"])) {
            throw new \RuntimeException("Message error");
        }

        switch ($message["type"]) {
            case "import-socket":
                $uri = $message["payload"];

                $stream = ($this->bind)($uri);

                $socket = \socket_import_stream($this->socket->getResource());

                if (!\socket_sendmsg($socket, [
                        "iov" => [$uri],
                        "control" => [["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$stream]]],
                    ], 0)
                ) {
                    throw new \RuntimeException("Could not transfer socket");
                }
                break;

            case "data":
                $this->emitter->emit($message["payload"]);
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }
}
