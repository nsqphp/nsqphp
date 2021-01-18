<?php

declare(strict_types=1);

namespace Nsq;

use Generator;
use LogicException;

final class Subscriber
{
    private Reader $reader;

    public function __construct(Reader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * @psalm-return Generator<int, Envelope|null, true|null, void>
     */
    public function subscribe(string $topic, string $channel, ?float $timeout = 0): Generator
    {
        $reader = $this->reader;
        $reader->sub($topic, $channel);
        $reader->rdy(1);

        while (true) {
            $message = $reader->consume($timeout);

            if (null === $message) {
                if (true === yield null) {
                    break;
                }

                continue;
            }

            $finished = false;
            $envelop = new Envelope(
                $message,
                static function () use ($reader, $message, &$finished): void {
                    if ($finished) {
                        throw new LogicException('Can\'t ack, message already finished.');
                    }

                    $finished = true;

                    $reader->fin($message->id);
                },
                static function (int $timeout) use ($reader, $message, &$finished): void {
                    if ($finished) {
                        throw new LogicException('Can\'t retry, message already finished.');
                    }

                    $finished = true;

                    $reader->req($message->id, $timeout);
                },
                static function () use ($reader, $message): void {
                    $reader->touch($message->id);
                },
            );

            if (true === yield $envelop) {
                break;
            }

            $reader->rdy(1);
        }

        $reader->close();
    }
}
