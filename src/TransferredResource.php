<?php declare(strict_types=1);

namespace Amp\Cluster;

/**
 * @template-covariant T
 */
final class TransferredResource
{
    /**
     * @param resource $resource Stream-socket resource.
     * @param T $data
     */
    public function __construct(
        private readonly mixed $resource,
        private readonly mixed $data,
    ) {
    }

    /**
     * @return resource Stream-socket resource.
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
