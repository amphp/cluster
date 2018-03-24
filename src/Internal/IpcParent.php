<?php

namespace Amp\Cluster\Internal;

use Amp\Loop;
use Amp\Parallel\Context\Context;
use Amp\Promise;
use Amp\Socket\Socket;
use Monolog\Handler\HandlerInterface;
use function Amp\call;

class IpcParent {
    const PING_TIMEOUT = 10000;

    /** @var Socket */
    private $socket;

    /** @var callable */
    private $bind;

    /** @var callable */
    private $onData;

    /** @var Context */
    private $context;

    /** @var int */
    private $lastActivity;

    public function __construct(Context $context, Socket $socket, HandlerInterface $logger, callable $bind, callable $onData) {
        $this->socket = $socket;
        $this->bind = $bind;
        $this->onData = $onData;
        $this->lastActivity = \time();
        $this->context = $context;
    }

    public function send(string $event, $data = null): Promise {
        return $this->context->send([IpcClient::TYPE_DATA, $event, $data]);
    }

    public function run(): Promise {
        return call(function () {
            $watcher = Loop::repeat(self::PING_TIMEOUT / 2, function () {
                if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                    $this->socket->close();
                } else {
                    $this->context->send([IpcClient::TYPE_PING]);
                }
            });

            try {
                while (null !== $message = yield $this->context->receive()) {
                    $this->lastActivity = \time();
                    yield from $this->handleMessage($message);
                }

                return yield $this->context->join();
            } finally {
                Loop::cancel($watcher);

                $this->socket->close();
                $this->socket = null;
            }
        });
    }

    private function handleMessage(array $message): \Generator {
        \assert(\count($message) >= 1);

        switch ($message[0]) {
            case IpcClient::TYPE_IMPORT_SOCKET:
                $uri = $message[1];

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

                yield $this->context->send([IpcClient::TYPE_IMPORT_SOCKET]);
                break;

            case IpcClient::TYPE_SELECT_PORT:
                $uri = $message[1];
                $stream = ($this->bind)($uri);
                $uri = \stream_socket_get_name($stream, false);
                yield $this->context->send([IpcClient::TYPE_SELECT_PORT, $uri]);
                break;

            case IpcClient::TYPE_PING:
                break;

            case IpcClient::TYPE_DATA:
                ($this->onData)($message[1], $message[2]);
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }
}
