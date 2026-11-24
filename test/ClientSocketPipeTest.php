<?php declare(strict_types=1);

namespace Amp\Cluster\Test;

use Amp\Cluster\ClientSocketReceivePipe;
use Amp\Cluster\ClientSocketSendPipe;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use function Amp\async;

class ClientSocketPipeTest extends AsyncTestCase
{
    public function provideDataSizes(): iterable
    {
        yield 'small' => [100];
        yield 'medium' => [20_000];
        yield 'large' => [500_000];
    }

    /**
     * @dataProvider provideDataSizes
     */
    public function testTransferSocketWithData(int $size): void
    {
        [$sendSocket, $receiveSocket] = Socket\createSocketPair();

        $sendPipe = new ClientSocketSendPipe($sendSocket);
        $receivePipe = new ClientSocketReceivePipe($receiveSocket);

        [$local, $remote] = Socket\createSocketPair();

        $data = \str_repeat('.', $size);

        [$received] = Future\await([
            async($receivePipe->receive(...)),
            async(fn () => $sendPipe->send($remote, $data)),
        ]);

        self::assertSame($data, $received->getData());

        $local->write('test');
        self::assertSame('test', $received->getSocket()->read());

        $sendPipe->close();
        $receivePipe->close();
    }

    public function testTransferMultipleSocketsWithData(): void
    {
        $dataPackets = \array_map(
            fn (int $length) => \str_repeat('.', $length),
            [10, 50_000, 10, 300_000, 10],
        );

        [$sendSocket, $receiveSocket] = Socket\createSocketPair();

        $sendPipe = new ClientSocketSendPipe($sendSocket);
        $receivePipe = new ClientSocketReceivePipe($receiveSocket);

        $pairs = \array_map(fn () => Socket\createSocketPair(), $dataPackets);

        $futures = [];
        foreach ($dataPackets as $index => $packet) {
            [, $remote] = $pairs[$index];
            $futures[] = async(fn () => $sendPipe->send($remote, [$index, $packet]));
        }

        foreach ($dataPackets as $index => $packet) {
            $received = $receivePipe->receive();
            self::assertSame([$index, $packet], $received->getData());

            [$send] = $pairs[$index];
            $send->write((string) $index);
            self::assertSame((string) $index, $received->getSocket()->read());
        }

        Future\await($futures);

        $sendPipe->close();
        $receivePipe->close();
    }

    public function testTransferSocketWithDataAtLengthLimit(): void
    {
        $limit = 1024;
        [$sendSocket, $receiveSocket] = Socket\createSocketPair();

        $sendPipe = new ClientSocketSendPipe($sendSocket, dataLengthLimit: $limit);
        $receivePipe = new ClientSocketReceivePipe($receiveSocket, dataLengthLimit: $limit);

        [$remote] = Socket\createSocketPair();

        $data = \str_repeat('.', $limit >> 1);

        $future = async(fn () => $sendPipe->send($remote, $data));

        self::assertSame($data, $receivePipe->receive()->getData());
        $future->await();

        $sendPipe->close();
        $receivePipe->close();
    }

    public function testSendDataExceedingLengthLimit(): void
    {
        $limit = 1024;
        [$sendSocket, $receiveSocket] = Socket\createSocketPair();

        $sendPipe = new ClientSocketSendPipe($sendSocket, dataLengthLimit: $limit);
        $receivePipe = new ClientSocketReceivePipe($receiveSocket, dataLengthLimit: $limit);

        [$remote] = Socket\createSocketPair();

        try {
            $sendPipe->send($remote, \str_repeat('.', $limit * 2));
            self::fail('Sending data exceeding the length limit should throw');
        } catch (SocketException $exception) {
            self::assertStringContainsString('exceeds the limit', $exception->getMessage());
        }

        // Nothing was sent, so the pipe remains usable.
        self::assertFalse($sendPipe->isClosed());

        $future = async(fn () => $sendPipe->send($remote, 'data'));

        self::assertSame('data', $receivePipe->receive()->getData());
        $future->await();

        $sendPipe->close();
        $receivePipe->close();
    }

    public function testReceiveDataExceedingLengthLimit(): void
    {
        $limit = 1024;
        [$sendSocket, $receiveSocket] = Socket\createSocketPair();

        $receivePipe = new ClientSocketReceivePipe($receiveSocket, dataLengthLimit: $limit);

        // Only a header (data length, then flags) declaring an oversized message is written. The receiver must reject
        // it without waiting for (and buffering) the data.
        $sendSocket->write(\pack('NC', $limit + 1, 0));

        try {
            $receivePipe->receive(new TimeoutCancellation(1));
            self::fail('Receiving data exceeding the length limit should throw');
        } catch (SocketException $exception) {
            self::assertStringContainsString('exceeds the limit', $exception->getMessage());
        }

        self::assertTrue($receivePipe->isClosed());

        $sendSocket->close();
    }

    public function testSendAfterClose(): void
    {
        [$sendSocket, $receiveSocket] = Socket\createSocketPair();

        $sendPipe = new ClientSocketSendPipe($sendSocket);
        $sendPipe->close();

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('The transfer socket has been closed');

        try {
            $sendPipe->send($receiveSocket, 'data');
        } finally {
            $receiveSocket->close();
        }
    }

    public function testDroppedReceivePipeClosesTransferSocket(): void
    {
        [$socket, $peer] = Socket\createSocketPair();

        $pipe = new ClientSocketReceivePipe($socket);

        unset($pipe, $socket);

        self::assertNull($peer->read(new TimeoutCancellation(1)));
    }

    public function testDroppedSendPipeClosesTransferSocket(): void
    {
        [$socket, $peer] = Socket\createSocketPair();

        $pipe = new ClientSocketSendPipe($socket);

        unset($pipe, $socket);

        self::assertNull($peer->read(new TimeoutCancellation(1)));
    }
}
