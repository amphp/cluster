<?php declare(strict_types=1);

namespace Amp\Cluster\Internal;

use Amp\ByteStream\ResourceStream;
use Amp\Closable;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\SocketException;
use Socket as ExtSocketResource;

/** @internal */
final class TransferSocket implements Closable
{
    use ForbidCloning;
    use ForbidSerialization;

    /** Messages are prefixed with their length as an unsigned 32-bit integer in network byte order. */
    private const HEADER_FORMAT = 'N';
    private const HEADER_LENGTH = 4;
    private const MAX_DATA_LENGTH_LIMIT = 0xffffffff; // Largest length representable in the header.

    public const DEFAULT_DATA_LENGTH_LIMIT = 1 << 20; // 1MiB

    private const RECEIVE_BUFFER_SIZE = 8192;

    /**
     * Linux returns at most one set of transferred sockets per read, but other systems may combine several,
     * so space is reserved for more than one.
     */
    private const SOCKETS_PER_READ_LIMIT = 16;

    private readonly ExtSocketResource $socket;

    /** @var \SplQueue<resource> Sockets received before the remainder of their corresponding message. */
    private readonly \SplQueue $receivedStreams;

    private string $receiveBuffer = '';

    private readonly \Closure $errorHandler;

    private readonly DeferredFuture $onClose;

    /**
     * @param positive-int $dataLengthLimit Maximum bytes which may be sent or received as data alongside a socket.
     *     May not exceed 2^32 - 1 bytes, the largest length representable in the message header.
     * @param positive-int $socketsPerReadLimit Maximum number of sockets which may be received in a single read.
     *     Additional sockets are discarded by the system, so receiving more throws a {@see SocketException}.
     */
    public function __construct(
        ResourceStream $socket,
        private readonly int $dataLengthLimit = self::DEFAULT_DATA_LENGTH_LIMIT,
        private readonly int $socketsPerReadLimit = self::SOCKETS_PER_READ_LIMIT,
    ) {
        if (!\extension_loaded('sockets')) {
            throw new \Error('ext-sockets is required for ' . self::class);
        }

        /** @psalm-suppress DocblockTypeContradiction Extra manual check to enforce docblock types. */
        if ($this->dataLengthLimit <= 0) {
            throw new \ValueError('The data length limit must be greater than zero');
        }

        if ($this->dataLengthLimit > self::MAX_DATA_LENGTH_LIMIT) {
            throw new \ValueError(\sprintf(
                'The data length limit must not exceed %d bytes; got %d',
                self::MAX_DATA_LENGTH_LIMIT,
                $this->dataLengthLimit,
            ));
        }

        /** @psalm-suppress DocblockTypeContradiction Extra manual check to enforce docblock types. */
        if ($socketsPerReadLimit <= 0) {
            throw new \ValueError('The maximum number of sockets per read must be greater than zero');
        }

        $streamResource = $socket->getResource();
        if (!\is_resource($streamResource)) {
            throw new SocketException('The provided socket has already been closed');
        }

        $socketResource = \socket_import_stream($streamResource);
        if (!$socketResource instanceof ExtSocketResource) {
            throw new SocketException('Unable to import transfer socket from stream socket resource');
        }

        $this->socket = $socketResource;
        $this->receivedStreams = new \SplQueue();
        $this->errorHandler = $errorHandler = static fn () => true;
        $this->onClose = new DeferredFuture();

        $this->onClose(static function () use ($socketResource, $errorHandler): void {
            \set_error_handler($errorHandler);
            try {
                \socket_close($socketResource);
            } finally {
                \restore_error_handler();
            }
        });
    }

    public function __destruct()
    {
        $this->close();
    }

    #[\Override]
    public function close(): void
    {
        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->onClose->isComplete();
    }

    #[\Override]
    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    /**
     * Reads available data from the transfer socket, returning any messages which have been completely received.
     *
     * The transfer socket is a stream socket, so a message may be split across several reads, and a single read
     * may contain parts of several messages. Each message is prefixed with its length and the transferred socket
     * is attached to the first byte of the message, so it will have always been received by the time the message
     * is complete.
     *
     * @return list<TransferredResource<string>> Empty if no complete message is available.
     *
     * @throws SocketException
     *
     * @psalm-suppress InvalidArrayOffset $data array is overwritten by socket_recvmsg().
     */
    public function receiveSockets(): array
    {
        $data = [
            "buffer_size" => self::RECEIVE_BUFFER_SIZE,
            "controllen" => \socket_cmsg_space(\SOL_SOCKET, \SCM_RIGHTS, $this->socketsPerReadLimit),
        ];

        // Error checked manually if socket_recvmsg() fails.
        \set_error_handler($this->errorHandler);
        \socket_clear_error();

        try {
            $length = \socket_recvmsg($this->socket, $data, \MSG_DONTWAIT);
            if ($length === false) {
                /* Purposely omitting $this->socket from socket_last_error(),
                 * as the error will not be socket-specific. */
                $errorCode = \socket_last_error();
                if ($errorCode === \SOCKET_EAGAIN) {
                    return [];
                }

                throw new SocketException(\sprintf(
                    'Could not receive socket: (%d) %s',
                    $errorCode,
                    \socket_strerror($errorCode),
                ));
            }
        } finally {
            \restore_error_handler();
        }

        if ($length === 0) {
            throw new SocketException('The transfer socket was closed by the peer');
        }

        if ($data["flags"] & \MSG_CTRUNC) {
            throw new SocketException('Too many sockets received at once; some transferred sockets were discarded');
        }

        foreach ($data["control"] ?? [] as $message) {
            if ($message["level"] !== \SOL_SOCKET || $message["type"] !== \SCM_RIGHTS) {
                continue;
            }

            foreach ($message["data"] as $transferredSocket) {
                if (!$transferredSocket instanceof ExtSocketResource) {
                    throw new SocketException('Received a file descriptor which is not a socket');
                }

                $transferredStream = \socket_export_stream($transferredSocket);
                if (!$transferredStream) {
                    throw new SocketException('Failed to import socket to a stream socket resource');
                }

                $this->receivedStreams->push($transferredStream);
            }
        }

        $this->receiveBuffer .= \implode($data["iov"]);

        $received = [];
        while (\strlen($this->receiveBuffer) >= self::HEADER_LENGTH) {
            /**
             * @var int $length
             * @psalm-suppress PossiblyInvalidArrayAccess unpack() cannot fail with at least HEADER_LENGTH bytes.
             */
            $length = \unpack(self::HEADER_FORMAT, $this->receiveBuffer)[1];

            // Checked before buffering the message so a peer cannot force the entire announced length to be buffered.
            if ($length > $this->dataLengthLimit) {
                throw new SocketException(\sprintf(
                    'The data received with a transferred socket exceeds the limit of %d bytes; got %d',
                    $this->dataLengthLimit,
                    $length,
                ));
            }

            if (\strlen($this->receiveBuffer) < self::HEADER_LENGTH + $length) {
                break;
            }

            $transferredData = \substr($this->receiveBuffer, self::HEADER_LENGTH, $length);
            $this->receiveBuffer = \substr($this->receiveBuffer, self::HEADER_LENGTH + $length);

            if ($this->receivedStreams->isEmpty()) {
                throw new SocketException('Received a message without an accompanying socket');
            }

            $transferredStream = $this->receivedStreams->shift();

            $received[] = new TransferredResource($transferredStream, $transferredData);
        }

        return $received;
    }

    /**
     * Begins transferring the given stream socket along with the given data.
     *
     * @param resource $stream Stream socket resource.
     *
     * @return string|null Null if the socket buffer was full and nothing was sent, in which case this method must
     *     be called again. Otherwise, the portion of the message which has not yet been sent, which must be sent
     *     using {@see self::sendData()} before transferring another socket. An empty string indicates the
     *     entire message was sent.
     *
     * @throws SocketException
     */
    public function sendSocket($stream, string $data): ?string
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if (!\is_resource($stream)) {
            throw new SocketException('The stream resource closed before being transferred');
        }

        if (\strlen($data) > $this->dataLengthLimit) {
            throw new SocketException(\sprintf(
                'The data sent with a transferred socket exceeds the limit of %d bytes; got %d',
                $this->dataLengthLimit,
                \strlen($data),
            ));
        }

        $data = \pack(self::HEADER_FORMAT, \strlen($data)) . $data;

        // Error checked manually if socket_sendmsg() fails.
        \set_error_handler($this->errorHandler);
        \socket_clear_error($this->socket);

        try {
            $written = \socket_sendmsg($this->socket, [
                "iov" => [$data],
                "control" => [
                    ["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$stream]],
                ],
            ], \MSG_DONTWAIT);

            if ($written === false) {
                $errorCode = \socket_last_error($this->socket);
                if ($errorCode === \SOCKET_EAGAIN) {
                    // Socket buffer full, try again later.
                    return null;
                }

                throw new SocketException(\sprintf(
                    'Could not send socket: (%d) %s',
                    $errorCode,
                    \socket_strerror($errorCode),
                ));
            }
        } finally {
            \restore_error_handler();
        }

        return \substr($data, $written);
    }

    /**
     * Continues sending data partially sent by {@see self::sendSocket()}.
     *
     * @return string The portion of the data which has still not been sent. An empty string indicates the
     *     entire message was sent.
     *
     * @throws SocketException
     */
    public function sendData(string $data): string
    {
        // Error checked manually if socket_send() fails.
        \set_error_handler($this->errorHandler);
        \socket_clear_error($this->socket);

        try {
            $written = \socket_send($this->socket, $data, \strlen($data), \MSG_DONTWAIT);

            if ($written === false) {
                $errorCode = \socket_last_error($this->socket);
                if ($errorCode === \SOCKET_EAGAIN) {
                    // Socket buffer full, try again later.
                    return $data;
                }

                throw new SocketException(\sprintf(
                    'Could not send remaining transfer data: (%d) %s',
                    $errorCode,
                    \socket_strerror($errorCode),
                ));
            }
        } finally {
            \restore_error_handler();
        }

        return \substr($data, $written);
    }
}
