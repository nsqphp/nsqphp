<?php

declare(strict_types=1);

namespace Nsq\Protocol;

use Nsq\Bytes;

/**
 * @psalm-immutable
 */
final class Response extends Frame
{
    public const OK = 'OK';
    public const HEARTBEAT = '_heartbeat_';

    public function __construct(public string $msg)
    {
        parent::__construct(\strlen($this->msg) + Bytes::BYTES_TYPE);
    }

    public function isOk(): bool
    {
        return self::OK === $this->msg;
    }

    public function isHeartBeat(): bool
    {
        return self::HEARTBEAT === $this->msg;
    }

    /**
     * @return array<mixed, mixed>
     */
    public function toArray(): array
    {
        return json_decode($this->msg, true, flags: JSON_THROW_ON_ERROR);
    }
}
