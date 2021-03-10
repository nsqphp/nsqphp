<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Promise;
use Nsq\Exception\MessageException;
use function Amp\call;

final class Message
{
    private bool $processed = false;

    public function __construct(
        public string $id,
        public string $body,
        public int $timestamp,
        public int $attempts,
        private Consumer $consumer,
    ) {
    }

    public static function compose(Frame\Message $message, Consumer $consumer): self
    {
        return new self(
            $message->id,
            $message->body,
            $message->timestamp,
            $message->attempts,
            $consumer,
        );
    }

    /**
     * @return Promise<void>
     */
    public function finish(): Promise
    {
        return call(function (): \Generator {
            if ($this->processed) {
                throw MessageException::processed($this);
            }

            yield $this->consumer->fin($this->id);

            $this->processed = true;
        });
    }

    /**
     * @return Promise<void>
     */
    public function requeue(int $timeout): Promise
    {
        return call(function () use ($timeout): \Generator {
            if ($this->processed) {
                throw MessageException::processed($this);
            }

            yield $this->consumer->req($this->id, $timeout);

            $this->processed = true;
        });
    }

    /**
     * @return Promise<void>
     */
    public function touch(): Promise
    {
        return call(function (): \Generator {
            if ($this->processed) {
                throw MessageException::processed($this);
            }

            yield $this->consumer->touch($this->id);

            $this->processed = true;
        });
    }
}
