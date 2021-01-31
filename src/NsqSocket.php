<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Exception\ConnectionFail;
use Nsq\Socket\Socket;
use PHPinnacle\Buffer\ByteBuffer;
use Throwable;

final class NsqSocket
{
    private Buffer $input;

    private ByteBuffer $output;

    public function __construct(
        private Socket $socket,
    ) {
        $this->input = new Buffer();
        $this->output = new ByteBuffer();
    }

    public function write(string $command, string $data = null): void
    {
        $this->output->append($command.PHP_EOL);

        if (null !== $data) {
            $this->output->appendUint32(\strlen($data));
            $this->output->append($data);
        }

        $this->socket->write($this->output->flush());
    }

    public function wait(float $timeout): bool
    {
        return $this->socket->selectRead($timeout);
    }

    public function read(): Buffer
    {
        $buffer = $this->input;

        $size = Bytes::BYTES_SIZE;

        do {
            $buffer->append(
                $this->socket->read($size),
            );

            $size -= $buffer->size();
        } while ($buffer->size() < Bytes::BYTES_SIZE);

        if ('' === $buffer->bytes()) {
            throw new ConnectionFail('Probably connection closed.');
        }

        $size = $buffer->consumeSize();

        do {
            $buffer->append(
                $this->socket->read($size - $buffer->size()),
            );
        } while ($buffer->size() < $size);

        return $buffer;
    }

    public function close(): void
    {
        try {
            $this->socket->close();
        } catch (Throwable) {
        }
    }
}
