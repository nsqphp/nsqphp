<?php

declare(strict_types=1);

namespace Nsq;

use PHPinnacle\Buffer\ByteBuffer;

final class Buffer
{
    private ByteBuffer $buffer;

    public function __construct(string $initial = '')
    {
        $this->buffer = new ByteBuffer($initial);
    }

    public function append(string $data): self
    {
        $this->buffer->append($data);

        return $this;
    }

    public function consumeSize(): int
    {
        /** @see Bytes::BYTES_SIZE */
        return $this->buffer->consumeUint32();
    }

    public function consumeType(): int
    {
        /** @see Bytes::BYTES_TYPE */
        return $this->buffer->consumeUint32();
    }

    public function consumeTimestamp(): int
    {
        /** @see Bytes::BYTES_TIMESTAMP */
        return $this->buffer->consumeInt64();
    }

    public function consumeAttempts(): int
    {
        /** @see Bytes::BYTES_ATTEMPTS */
        return $this->buffer->consumeUint16();
    }

    public function consumeId(): string
    {
        return $this->buffer->consume(Bytes::BYTES_ID);
    }

    public function size(): int
    {
        return $this->buffer->size();
    }

    public function bytes(): string
    {
        return $this->buffer->bytes();
    }

    public function flush(): string
    {
        return $this->buffer->flush();
    }
}
