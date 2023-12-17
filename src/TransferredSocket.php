<?php declare(strict_types=1);

namespace Amp\Cluster;

use Amp\ByteStream\ResourceStream;
use Amp\Socket\Socket;

/**
 * @template-covariant T
 */
final class TransferredSocket
{
    /**
     * @param T $data
     */
    public function __construct(
        private readonly Socket&ResourceStream $socket,
        private readonly mixed $data,
    ) {
    }

    public function getSocket(): Socket&ResourceStream
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
