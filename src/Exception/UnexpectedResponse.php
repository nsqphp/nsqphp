<?php

declare(strict_types=1);

namespace Nsq\Exception;

use RuntimeException;

final class UnexpectedResponse extends RuntimeException implements NsqException
{
    /**
     * @codeCoverageIgnore
     */
    public static function null(): self
    {
        return new self('Response was expected, but null received.');
    }
}
