<?php

declare(strict_types=1);

namespace Nsq;

use LogicException;

final class Envelope
{
    /**
     * @psalm-readonly
     */
    public Message $message;

    private bool $finished = false;

    private Reader $connection;

    public function __construct(Message $message, Reader $connection)
    {
        $this->message = $message;
        $this->connection = $connection;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function finish(): void
    {
        if ($this->finished) {
            throw new LogicException('Can\'t finish message as it already finished.');
        }

        $this->connection->fin($this->message->id);
        $this->finished = true;
    }

    public function requeue(int $timeout): void
    {
        if ($this->finished) {
            throw new LogicException('Can\'t requeue message as it already finished.');
        }

        $this->connection->req($this->message->id, $timeout);
        $this->finished = true;
    }

    public function touch(): void
    {
        if ($this->finished) {
            throw new LogicException('Can\'t touch message as it already finished.');
        }

        $this->connection->touch($this->message->id);
    }
}
