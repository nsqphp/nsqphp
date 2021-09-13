<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Psr\Log\LogLevel;

final class LookupException extends NsqException
{
    public function level(): string
    {
        return match ($this->getMessage()) {
            'TOPIC_NOT_FOUND' => LogLevel::DEBUG,
            default => LogLevel::WARNING,
        };
    }
}
