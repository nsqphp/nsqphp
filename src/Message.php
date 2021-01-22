<?php

declare(strict_types=1);

namespace Nsq;

use LogicException;

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

    public function __construct(int $timestamp, int $attempts, string $id, string $body, Reader $reader)
    {
        $this->timestamp = $timestamp;
        $this->attempts = $attempts;
        $this->id = $id;
        $this->body = $body;

        $this->connection = $reader;
    }

    private bool $finished = false;

    private Reader $connection;

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function finish(): void
    {
        if ($this->finished) {
            throw new LogicException('Can\'t finish message as it already finished.');
        }

        $this->connection->fin($this->id);
        $this->finished = true;
    }

    public function requeue(int $timeout): void
    {
        if ($this->finished) {
            throw new LogicException('Can\'t requeue message as it already finished.');
        }

        $this->connection->req($this->id, $timeout);
        $this->finished = true;
    }

    public function touch(): void
    {
        if ($this->finished) {
            throw new LogicException('Can\'t touch message as it already finished.');
        }

        $this->connection->touch($this->id);
    }
}
