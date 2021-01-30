<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Throwable;

final class ConnectionFail extends NsqException
{
    /**
     * @codeCoverageIgnore
     */
    public static function fromThrowable(Throwable $throwable): self
    {
        return new self($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
    }
}
