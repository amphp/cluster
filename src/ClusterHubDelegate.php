<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\Cancellation;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Socket\ResourceSocket;

final class ClusterHubDelegate implements ClusterHub
{
    /**
     * @param IpcHub $hub Sockets returned from {@see IpcHub::accept()} must be an instance of {@see ResourceSocket}.
     */
    public function __construct(private readonly IpcHub $hub)
    {
    }

    public function accept(string $key, ?Cancellation $cancellation = null): ResourceSocket
    {
        $socket = $this->hub->accept($key, $cancellation);
        if (!$socket instanceof ResourceSocket) {
            throw new \TypeError(\sprintf(
                'Delegate %s instance %s::accept() did return an instance of %s',
                IpcHub::class,
                \get_class($this->hub),
                ResourceSocket::class,
            ));
        }

        return $socket;
    }

    public function close(): void
    {
        $this->hub->close();
    }

    public function isClosed(): bool
    {
        return $this->hub->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->hub->onClose($onClose);
    }

    public function getUri(): string
    {
        return $this->hub->getUri();
    }

    public function generateKey(): string
    {
        return $this->hub->generateKey();
    }
}
