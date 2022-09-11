<?php

declare(strict_types=1);

namespace Nsq;

abstract class Frame
{
    public const TYPE_RESPONSE = 0;
    public const TYPE_ERROR = 1;
    public const TYPE_MESSAGE = 2;

    public function __construct(public int $type)
    {
    }

    public function response(): bool
    {
        return self::TYPE_RESPONSE === $this->type;
    }

    public function error(): bool
    {
        return self::TYPE_ERROR === $this->type;
    }

    public function message(): bool
    {
        return self::TYPE_MESSAGE === $this->type;
    }
}
