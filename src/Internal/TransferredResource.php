<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * @template-covariant T
 */
final class TransferredResource
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param resource|null $resource Stream-socket resource, or null if the data was sent without a socket.
     * @param T $data
     */
    public function __construct(
        private readonly mixed $resource,
        private readonly mixed $data,
    ) {
    }

    /**
     * @return resource|null Stream-socket resource, or null if the data was sent without a socket.
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * @return T
     */
    public function getData(): mixed
    {
        return $this->data;
    }
}
