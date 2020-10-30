<?php

namespace Amp\Cluster\Internal;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Socket\ResourceSocket;
use function Amp\await;

/** @internal */
final class IpcClient
{
    public const TYPE_PING = 0;
    public const TYPE_DATA = 1;
    public const TYPE_IMPORT_SOCKET = 2;
    public const TYPE_SELECT_PORT = 3;
    public const TYPE_LOG = 4;

    private ?string $importWatcher = null;

    private Channel $channel;

    /** @var callable */
    private $onData;

    private \SplQueue $pendingResponses;

    public function __construct(callable $onData, Channel $channel, ResourceSocket $socket = null)
    {
        $this->channel = $channel;
        $this->onData = $onData;
        $this->pendingResponses = $pendingResponses = new \SplQueue;

        if ($socket === null) {
            return;
        }

        $this->importWatcher = Loop::onReadable($socket->getResource(), static function (string $watcher, $socket) use (
            $pendingResponses
        ): void {
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

    public function __destruct()
    {
        if ($this->importWatcher !== null) {
            Loop::cancel($this->importWatcher);
        }
    }

    public function run(): void
    {
        while (null !== $message = $this->channel->receive()) {
            $this->handleMessage($message);
        }
    }

    public function close(): void
    {
        $this->channel->send(null);
    }

    private function handleMessage(array $message): void
    {
        \assert(\count($message) >= 1);

        switch ($message[0]) {
            case self::TYPE_PING:
                \assert(\count($message) === 1);
                $this->channel->send([self::TYPE_PING]);
                break;

            case self::TYPE_IMPORT_SOCKET:
                \assert(\count($message) === 1);
                Loop::enable($this->importWatcher);
                break;

            case self::TYPE_SELECT_PORT:
                \assert(\count($message) === 2);
                if ($this->pendingResponses->isEmpty()) {
                    throw new \RuntimeException("Unexpected select-port message.");
                }

                $deferred = $this->pendingResponses->shift();
                $deferred->resolve($message[1]);
                break;

            case self::TYPE_DATA:
                \assert(\count($message) === 3);
                ($this->onData)($message[1], $message[2]);
                break;

            case self::TYPE_LOG:
                throw new \UnexpectedValueException("Log messages should never be sent by the parent");

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }

    public function importSocket(string $uri)
    {
        $deferred = new Deferred;
        $this->pendingResponses->push($deferred);

        $this->channel->send([self::TYPE_IMPORT_SOCKET, $uri]);

        return await($deferred->promise());
    }

    public function selectPort(string $uri): string
    {
        $deferred = new Deferred;
        $this->pendingResponses->push($deferred);

        $this->channel->send([self::TYPE_SELECT_PORT, $uri]);

        return await($deferred->promise());
    }

    public function send(string $event, $data): void
    {
        $this->channel->send([self::TYPE_DATA, $event, $data]);
    }

    public function log(array $record): void
    {
        $this->channel->send([self::TYPE_LOG, $record]);
    }
}
