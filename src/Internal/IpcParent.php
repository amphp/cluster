<?php

namespace Amp\Cluster\Internal;

use Amp\Parallel\Context\Context;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceSocketAddress;
use Amp\Socket\Socket;
use Monolog\Logger;
use Revolt\EventLoop;
use function Amp\async;

/** @internal */
final class IpcParent
{
    private const PING_TIMEOUT = 10;

    private ?Socket $socket;

    /** @var callable */
    private $bind;

    /** @var callable */
    private $onData;

    private Logger $logger;

    private Context $context;

    private ?string $watcher = null;

    private int $lastActivity;

    public function __construct(
        Context $context,
        Logger $logger,
        callable $bind,
        callable $onData,
        Socket $socket = null
    ) {
        $this->socket = $socket;
        $this->bind = $bind;
        $this->logger = $logger;
        $this->onData = $onData;
        $this->lastActivity = \time();
        $this->context = $context;
    }

    public function send(string $event, $data = null): void
    {
        $this->context->send([IpcClient::TYPE_DATA, $event, $data]);
    }

    public function run(): void
    {
        $this->watcher = EventLoop::repeat(self::PING_TIMEOUT / 2, fn () => async(function (): void {
            if ($this->lastActivity < \time() - self::PING_TIMEOUT) {
                $this->shutdown();
            } else {
                $this->context->send([IpcClient::TYPE_PING]);
            }
        }));

        try {
            while (null !== $message = $this->context->receive()) {
                $this->lastActivity = \time();
                $this->handleMessage($message);
            }

            $this->context->join();
        } finally {
            EventLoop::cancel($this->watcher);

            if ($this->socket !== null) {
                $this->socket->close();
                $this->socket = null;
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->watcher) {
            EventLoop::disable($this->watcher);
        }

        $this->context->send(null);
    }

    private function handleMessage(array $message): void
    {
        \assert(\count($message) >= 1);

        switch ($message[0]) {
            case IpcClient::TYPE_IMPORT_SOCKET:
                \assert(\count($message) === 2);
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

                $this->context->send([IpcClient::TYPE_IMPORT_SOCKET]);
                break;

            case IpcClient::TYPE_SELECT_PORT:
                \assert(\count($message) === 2);
                $uri = $message[1];
                $stream = ($this->bind)($uri);
                $uri = ResourceSocketAddress::fromLocal($stream)->toString(); // Work around stream_socket_get_name + IPv6
                $this->context->send([IpcClient::TYPE_SELECT_PORT, $uri]);
                break;

            case IpcClient::TYPE_PING:
                break;

            case IpcClient::TYPE_DATA:
                \assert(\count($message) === 3);
                ($this->onData)($message[1], $message[2]);
                break;

            case IpcClient::TYPE_LOG:
                \assert(\count($message) === 2);
                foreach ($this->logger->getHandlers() as $handler) {
                    $handler->handle($message[1]);
                }
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type: " . $message[0]);
        }
    }
}
