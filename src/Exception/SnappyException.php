<?php

declare(strict_types=1);

namespace Nsq\Exception;

final class SnappyException extends NsqException
{
    public static function notInstalled(): self
    {
        return new self('Snappy extension not installed.');
    }

    public static function invalidHeader(): self
    {
        return new self('Invalid snappy protocol header.');
    }
}
