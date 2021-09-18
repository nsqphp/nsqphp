<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Promise;
use Nsq\Exception\MessageException;

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

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    /**
     * @psalm-return Promise<bool>
     */
    public function finish(): Promise
    {
        $this->markAsProcessedOrFail();

        return $this->consumer->fin($this->id);
    }

    /**
     * @psalm-param positive-int|0 $timeout
     *
     * @psalm-return Promise<bool>
     */
    public function requeue(int $timeout): Promise
    {
        $this->markAsProcessedOrFail();

        return $this->consumer->req($this->id, $timeout);
    }

    /**
     * @psalm-return Promise<bool>
     */
    public function touch(): Promise
    {
        if ($this->processed) {
            throw MessageException::processed($this);
        }

        return $this->consumer->touch($this->id);
    }

    private function markAsProcessedOrFail(): void
    {
        if ($this->processed) {
            throw MessageException::processed($this);
        }

        $this->processed = true;
    }
}
