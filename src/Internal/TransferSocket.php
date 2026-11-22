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

    private const HEADER_PACK_FORMAT = 'NC';
    private const HEADER_UNPACK_FORMAT = 'Nlength/Cflags';
    private const HEADER_LENGTH = 5;

    /** Set if a socket is attached to the message. */
    private const FLAG_SOCKET = 0x01;
    private const FLAG_MASK = self::FLAG_SOCKET;

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
     * @param positive-int $dataLengthLimit Maximum bytes which may be sent or received as data in each message.
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
     * @return list<TransferredResource<string>> Empty if no complete message is available. The resource of each
     *     is null if the message was sent without a socket.
     *
     * @throws SocketException
     *
     * @psalm-suppress InvalidArrayOffset $data array is overwritten by socket_recvmsg().
     */
    public function receiveMessages(): array
    {
        if ($this->isClosed()) {
            throw new SocketException('The transfer socket has been closed');
        }

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
             * @var array{length: int, flags: int} $header
             * @psalm-suppress PossiblyFalseReference unpack() cannot fail with at least HEADER_LENGTH bytes.
             */
            $header = \unpack(self::HEADER_UNPACK_FORMAT, $this->receiveBuffer);
            ['length' => $length, 'flags' => $flags] = $header;

            if ($flags & ~self::FLAG_MASK) {
                throw new SocketException(\sprintf('Received a message with unknown flags: 0x%02x', $flags));
            }

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

            $transferredStream = null;
            if ($flags & self::FLAG_SOCKET) {
                if ($this->receivedStreams->isEmpty()) {
                    throw new SocketException('Received a message without its accompanying socket');
                }

                $transferredStream = $this->receivedStreams->shift();
            }

            $received[] = new TransferredResource($transferredStream, $transferredData);
        }

        return $received;
    }

    /**
     * Create a message to be sent on the transfer socket.
     *
     * @param resource|null $stream Stream socket resource, or null to send the data without a socket.
     *
     * @throws SocketException
     */
    public function createMessage(string $data, mixed $stream): TransferMessage
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if ($stream !== null && !\is_resource($stream)) {
            throw new SocketException('The stream resource closed before being transferred');
        }

        if (\strlen($data) > $this->dataLengthLimit) {
            throw new SocketException(\sprintf(
                'The data sent with a transferred socket exceeds the limit of %d bytes; got %d',
                $this->dataLengthLimit,
                \strlen($data),
            ));
        }

        $flags = $stream !== null ? self::FLAG_SOCKET : 0;
        $data = \pack(self::HEADER_PACK_FORMAT, \strlen($data), $flags) . $data;

        return TransferMessage::forDataAndStream($data, $stream);
    }

    /**
     * @return TransferMessage|null Returns a new instance of {@see TransferMessage} if the message did not complete
     *      sending, requiring another call to this method with the returned message once the socket is writable.
     *
     * @throws SocketException
     */
    public function sendMessage(TransferMessage $message): ?TransferMessage
    {
        if ($this->isClosed()) {
            throw new SocketException('The transfer socket has been closed');
        }

        /** @psalm-suppress DocblockTypeContradiction */
        if ($message->stream !== null && !\is_resource($message->stream)) {
            throw new SocketException('The stream resource closed before being transferred');
        }

        $msg = ["iov" => [$message->data]];
        if ($message->stream !== null) {
            $msg["control"] = [
                ["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$message->stream]],
            ];
        }

        // Error checked manually if socket_sendmsg() fails.
        \set_error_handler($this->errorHandler);
        \socket_clear_error($this->socket);

        try {
            $written = \socket_sendmsg($this->socket, $msg, \MSG_DONTWAIT);

            if ($written === false) {
                $errorCode = \socket_last_error($this->socket);
                if ($errorCode === \SOCKET_EAGAIN) {
                    // Socket buffer full, try again later.
                    return $message;
                }

                throw new SocketException(\sprintf(
                    'Could not send message: (%d) %s',
                    $errorCode,
                    \socket_strerror($errorCode),
                ));
            }
        } finally {
            \restore_error_handler();
        }

        $data = \substr($message->data, $written);
        if ($data === '') {
            return null;
        }

        return $message->withData($data);
    }
}
