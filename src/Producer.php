<?php

declare(strict_types=1);

namespace Nsq;

use function array_map;
use function implode;
use function pack;

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
     * @psalm-param array<mixed, mixed> $bodies
     *
     * @psalm-suppress PossiblyFalseOperand
     */
    public function mpub(string $topic, array $bodies): void
    {
        $num = pack('N', \count($bodies));

        $mb = implode('', array_map(static function ($body): string {
            return pack('N', \strlen($body)).$body;
        }, $bodies));

        $this->command('MPUB', $topic, $num.$mb)->checkIsOK();
    }

    public function dpub(string $topic, string $body, int $delay): void
    {
        $this->command('DPUB', [$topic, $delay], $body)->checkIsOK();
    }
}
