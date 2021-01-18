<?php

declare(strict_types=1);

namespace Nsq;

/**
 * @psalm-immutable
 */
final class Message
{
    public int $timestamp;

    public int $attempts;

    public string $id;

    public string $body;

    public function __construct(int $timestamp, int $attempts, string $id, string $body)
    {
        $this->timestamp = $timestamp;
        $this->attempts = $attempts;
        $this->id = $id;
        $this->body = $body;
    }
}
