<?php

namespace Amp\Cluster;

use function Amp\call;
use Amp\Parallel\Sync\ChannelledStream;
use Amp\Promise;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;

class Worker {
    const HEADER_LENGTH = 5;

    /** @var \Amp\Socket\Socket */
    private $socket;

    private $channel;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var callable */
    private $listen;

    public function __construct(Socket $socket, PsrLogger $logger, callable $listen) {
        $this->socket = $socket;
        $this->channel = new ChannelledStream($this->socket, $this->socket);
        $this->logger = $logger;
        $this->listen = $listen;
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
                $stream = ($this->listen)($uri);

                $socket = \socket_import_stream($this->socket->getResource());

                if (!\socket_sendmsg($socket, [
                        "iov" => [$uri],
                        "control" => [["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$stream]]],
                    ], 0)
                ) {
                    throw new \RuntimeException("Could not transfer socket");
                }
                break;

            case "log":
                $data = $message["payload"];
                $this->logger->log($data["level"], $data["message"], $data["context"]);
                break;

            case "data":
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }
}
