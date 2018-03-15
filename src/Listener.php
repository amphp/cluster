<?php

namespace Amp\Cluster;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket\Server;
use Amp\Socket\ServerListenContext;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket;
use function Amp\call;

class Listener {
    const HEADER_LENGTH = 5;

    private $socket;

    public function __construct(Socket $socket, Channel $channel) {
        $this->socket = $socket;
        $this->channel = $channel;
    }

    public function listen(string $uri, ServerListenContext $listenContext = null, ServerTlsContext $tlsContext = null): Promise {
        $listenContext = $listenContext ?? new ServerListenContext;

        return call(function () use ($uri, $listenContext, $tlsContext) {
            yield $this->channel->send(["type" => "import-socket", "payload" => $uri]);

            $deferred = new Deferred;

            Loop::onReadable($this->socket->getResource(), function ($watcherId) use ($deferred, $listenContext, $tlsContext) {
                Loop::cancel($watcherId);

                $socket = \socket_import_stream($this->socket->getResource());

                $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)
                if (!\socket_recvmsg($socket, $data)) {
                    $deferred->fail(new \RuntimeException("Socket could not be received from parent process"));
                }

                if ($tlsContext) {
                    $context = \array_merge(
                        $listenContext->toStreamContextArray(),
                        $tlsContext->toStreamContextArray()
                    );
                } else {
                    $context = $listenContext->toStreamContextArray();
                }

                $socket = $data["control"][0]["data"][0];
                \socket_listen($socket, $context["socket"]["backlog"] ?? 0);

                $socket = \socket_export_stream($socket);
                \stream_context_set_option($socket, $context); // put eventual options like ssl back (per worker)

                $deferred->resolve(new Server($socket));
            });

            return $this->lastSend = $deferred->promise(); // Guards second watcher on socket by blocking calls to send()
        });
    }
}
