<?php

declare(strict_types=1);

namespace Nsq;

use PHPinnacle\Buffer\ByteBuffer;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Producer extends Connection
{
    public function pub(string $topic, string $body): void
    {
        $this->command('PUB', $topic, $body)->checkIsOK();
    }

    /**
     * @psalm-param array<int, mixed> $bodies
     */
    public function mpub(string $topic, array $bodies): void
    {
        static $buffer;
        $buffer ??= new ByteBuffer();

        $buffer->appendUint32(\count($bodies));

        foreach ($bodies as $body) {
            $buffer->appendUint32(\strlen($body));
            $buffer->append($body);
        }

        $this->command('MPUB', $topic, $buffer->flush())->checkIsOK();
    }

    public function dpub(string $topic, string $body, int $delay): void
    {
        $this->command('DPUB', [$topic, $delay], $body)->checkIsOK();
    }
}
