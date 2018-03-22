<?php

namespace Amp\Cluster\Internal;

use Amp\Emitter;
use Amp\Loop;
use Amp\Parallel\Context\Context;
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

    /** @var Context */
    private $context;

    /** @var int */
    private $lastActivity;

    public function __construct(Context $context, Socket $socket, PsrLogger $logger, Emitter $emitter, callable $bind) {
        $this->socket = $socket;
        $this->emitter = $emitter;
        $this->bind = $bind;
        $this->lastActivity = \time();
        $this->context = $context;
        $this->logger = $logger;
    }

    public function send($data): Promise {
        return $this->context->send(["type" => "data", "payload" => $data]);
    }

    public function run(): Promise {
        return call(function () {
            $watcher = Loop::repeat(self::PING_TIMEOUT / 2, function () {
                if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                    $this->socket->close();
                } else {
                    $this->context->send([
                        "type" => "ping",
                        "payload" => null,
                    ]);
                }
            });

            try {
                while (null !== $message = yield $this->context->receive()) {
                    $this->lastActivity = \time();
                    $this->handleMessage($message);
                }

                return yield $this->context->join();
            } finally {
                Loop::cancel($watcher);

                $this->socket->close();
                $this->socket = null;
            }
        });
    }

    private function handleMessage(array $message) {
        \assert(\array_key_exists("type", $message) && \array_key_exists("payload", $message));

        switch ($message["type"]) {
            case "import-socket":
                $uri = $message["payload"];

                $this->context->send(["type" => "import-socket", "payload" => null]);

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
                $this->logger->log($payload["level"], $payload["message"], $payload["context"]);
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
