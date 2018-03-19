<?php

namespace Amp\Cluster\Internal;

use Amp\Emitter;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket\Socket;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

class IpcParent {
    const PING_TIMEOUT = 10000;

    /** @var Socket */
    private $socket;

    /** @var PsrLogger */
    private $logger;

    /** @var Emitter */
    private $emitter;

    /** @var callable */
    private $bind;

    /** @var Channel */
    private $channel;

    /** @var int */
    private $lastActivity;

    public function __construct(Channel $channel, Socket $socket, PsrLogger $logger, Emitter $emitter, callable $bind) {
        $this->socket = $socket;
        $this->emitter = $emitter;
        $this->bind = $bind;
        $this->lastActivity = \time();
        $this->channel = $channel;
        $this->logger = $logger;
    }

    public function run(): Promise {
        return call(function () {
            $watcher = Loop::repeat(self::PING_TIMEOUT / 2, function () {
                if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                    $this->socket->close();
                } else {
                    $this->channel->send([
                        "type" => "ping",
                        "payload" => null,
                    ]);
                }
            });

            try {
                while (null !== $message = yield $this->channel->receive()) {
                    $this->lastActivity = \time();
                    $this->handleMessage($message);
                }
            } finally {
                Loop::cancel($watcher);

                $this->socket->close();
                $this->socket = null;
            }
        });
    }

    private function handleMessage(array $message) {
        if (!\array_key_exists("type", $message) || !\array_key_exists("payload", $message)) {
            throw new \RuntimeException("Message error");
        }

        switch ($message["type"]) {
            case "import-socket":
                $uri = $message["payload"];

                $this->channel->send(["type" => "import-socket", "payload" => null]);

                $stream = ($this->bind)($uri);
                $socket = \socket_import_stream($this->socket->getResource());

                \error_clear_last();
                if (!@\socket_sendmsg($socket, [
                    "iov" => [$uri],
                    "control" => [["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$stream]]],
                ], 0)) {
                    $error = \error_get_last()["message"] ?? "Unknown error";
                    throw new \RuntimeException("Could not transfer socket: " . $error);
                }
                break;

            case "log":
                $payload = $message["payload"];
                $this->logger->log($payload["level"], $payload["message"], [$payload["time"]]);
                break;

            case "pong":
                break;

            case "data":
                $this->emitter->emit($message["payload"]);
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }
}
