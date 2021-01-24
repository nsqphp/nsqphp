<?php

declare(strict_types=1);

namespace Nsq;

use Generator;
use InvalidArgumentException;
use function get_debug_type;
use function sprintf;

final class Subscriber
{
    public const STOP = 0;
    public const CHANGE_TIMEOUT = 1;
    public const TIMEOUT = 2;

    private Consumer $reader;

    public function __construct(Consumer $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @psalm-return Generator<int, Message|float|null, int|float|null, void>
     */
    public function subscribe(string $topic, string $channel, float $timeout = 0): Generator
    {
        $this->reader->sub($topic, $channel);

        while (true) {
            $this->reader->rdy(1);

            $command = yield $this->reader->consume($timeout);

            if (self::STOP === $command) {
                break;
            }

            if (self::CHANGE_TIMEOUT === $command) {
                $newTimeout = yield null;

                if (!\is_float($newTimeout)) {
                    throw new InvalidArgumentException(sprintf('Timeout must be float, "%s" given.', get_debug_type($newTimeout)));
                }

                $timeout = $newTimeout;
            }

            if (self::TIMEOUT === $command) {
                yield $timeout;
            }
        }

        $this->reader->disconnect();
    }
}
