<?php

declare(strict_types=1);

namespace Nsq;

use Generator;
use Nsq\Protocol\Message;

final class Subscriber
{
    public const STOP = 0;

    private Consumer $reader;

    public function __construct(Consumer $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @psalm-return Generator<int, Message|float|null, int|float|null, void>
     */
    public function subscribe(string $topic, string $channel): Generator
    {
        $this->reader->sub($topic, $channel);

        while (true) {
            $this->reader->rdy(1);

            $command = yield $this->reader->readMessage();

            if (self::STOP === $command) {
                break;
            }
        }

        $this->reader->disconnect();
    }
}
