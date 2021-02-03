<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Promise;
use PHPinnacle\Buffer\ByteBuffer;
use function Amp\call;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Producer extends Connection
{
    /**
     * @return Promise<void>
     */
    public function pub(string $topic, string $body): Promise
    {
        return call(function () use ($topic, $body): \Generator {
            yield $this->command('PUB', $topic, $body);
            yield $this->checkIsOK();
        });
    }

    /**
     * @psalm-param array<int, mixed> $bodies
     *
     * @return Promise<void>
     */
    public function mpub(string $topic, array $bodies): Promise
    {
        return call(function () use ($topic, $bodies): \Generator {
            $buffer = new ByteBuffer();

            $buffer->appendUint32(\count($bodies));

            foreach ($bodies as $body) {
                $buffer->appendUint32(\strlen($body));
                $buffer->append($body);
            }

            yield $this->command('MPUB', $topic, $buffer->flush());
            yield $this->checkIsOK();
        });
    }

    /**
     * @return Promise<void>
     */
    public function dpub(string $topic, string $body, int $delay): Promise
    {
        return call(function () use ($topic, $body, $delay): \Generator {
            yield $this->command('DPUB', [$topic, $delay], $body);
            yield $this->checkIsOK();
        });
    }
}
