<?php

declare(strict_types=1);

namespace Nsq;

use PHPinnacle\Buffer\ByteBuffer;

/**
 * @psalm-suppress
 */
final class Buffer extends ByteBuffer
{
    public function readUInt32LE(): int
    {
        $unpacked = unpack('V', $this->consume(4));

        \assert(\is_array($unpacked) && \array_key_exists(1, $unpacked));

        return $unpacked[1];
    }

    public function consumeTimestamp(): int
    {
        return $this->consumeUint64();
    }

    public function consumeAttempts(): int
    {
        return $this->consumeUint16();
    }

    public function consumeMessageID(): string
    {
        return $this->consume(16);
    }
}
