<?php

declare(strict_types=1);

namespace Nsq\Protocol;

use Amp\Failure;
use Amp\Promise;
use Nsq\Bytes;
use Nsq\Consumer;
use Nsq\Exception\MessageAlreadyFinished;

final class Message extends Frame
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
        parent::__construct(
            Bytes::BYTES_TYPE
            + Bytes::BYTES_TIMESTAMP
            + Bytes::BYTES_ATTEMPTS
            + Bytes::BYTES_ID
            + \strlen($body)
        );

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

    /**
     * @return Promise<void>
     */
    public function finish(): Promise
    {
        if ($this->finished) {
            return new Failure(MessageAlreadyFinished::finish($this));
        }

        $this->finished = true;

        return $this->consumer->fin($this->id);
    }

    /**
     * @return Promise<void>
     */
    public function requeue(int $timeout): Promise
    {
        if ($this->finished) {
            return new Failure(MessageAlreadyFinished::requeue($this));
        }

        $this->finished = true;

        return $this->consumer->req($this->id, $timeout);
    }

    /**
     * @return Promise<void>
     */
    public function touch(): Promise
    {
        if ($this->finished) {
            return new Failure(MessageAlreadyFinished::touch($this));
        }

        return $this->consumer->touch($this->id);
    }
}
