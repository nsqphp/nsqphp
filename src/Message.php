<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Exception\MessageAlreadyFinished;

final class Message
{
    /**
     * @psalm-readonly
     */
    public int $timestamp;

    /**
     * @psalm-readonly
     */
    public int $attempts;

    /**
     * @psalm-readonly
     */
    public string $id;

    /**
     * @psalm-readonly
     */
    public string $body;

    private bool $finished = false;

    private Consumer $consumer;

    public function __construct(int $timestamp, int $attempts, string $id, string $body, Consumer $consumer)
    {
        $this->timestamp = $timestamp;
        $this->attempts = $attempts;
        $this->id = $id;
        $this->body = $body;

        $this->consumer = $consumer;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function finish(): void
    {
        if ($this->finished) {
            throw MessageAlreadyFinished::finish($this);
        }

        $this->consumer->fin($this->id);
        $this->finished = true;
    }

    public function requeue(int $timeout): void
    {
        if ($this->finished) {
            throw MessageAlreadyFinished::requeue($this);
        }

        $this->consumer->req($this->id, $timeout);
        $this->finished = true;
    }

    public function touch(): void
    {
        if ($this->finished) {
            throw MessageAlreadyFinished::touch($this);
        }

        $this->consumer->touch($this->id);
    }
}
