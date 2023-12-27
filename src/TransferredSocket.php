<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\ResourceSocket;

/**
 * @template-covariant T
 */
final class TransferredSocket
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param T $data
     */
    public function __construct(
        private readonly ResourceSocket $socket,
        private readonly mixed $data,
    ) {
    }

    public function getSocket(): ResourceSocket
    {
        return $this->socket;
    }

    /**
     * @return T
     */
    public function getData(): mixed
    {
        return $this->data;
    }
}
