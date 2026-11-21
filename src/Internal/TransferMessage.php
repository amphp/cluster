<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/** @internal */
final class TransferMessage
{
    use ForbidCloning;
    use ForbidSerialization;

    public static function forDataAndStream(string $data, mixed $stream): self
    {
        return new self($data, $stream);
    }

    /**
     * @param resource|null $stream
     */
    private function __construct(
        public readonly string $data,
        public readonly mixed $stream = null,
        public readonly bool $initiated = false,
    ) {
    }

    public function withData(string $data): self
    {
        return new self(
            data: $data,
            initiated: true,
        );
    }
}
