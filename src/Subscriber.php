<?php

declare(strict_types=1);

namespace Nsq;

use Generator;

final class Subscriber extends Reader
{
    /**
     * @psalm-return Generator<int, Envelope|null, true|null, void>
     */
    public function subscribe(string $topic, string $channel, ?float $timeout = 0): Generator
    {
        $this->sub($topic, $channel);
        $this->rdy(1);

        while (true) {
            $message = $this->consume($timeout);

            if (null === $message) {
                if (true === yield null) {
                    break;
                }

                continue;
            }

            if (true === yield new Envelope($message, $this)) {
                break;
            }

            $this->rdy(1);
        }

        $this->disconnect();
    }
}
