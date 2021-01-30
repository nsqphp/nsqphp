<?php

declare(strict_types=1);

namespace Nsq\Protocol;

use Nsq\Bytes;
use function explode;

/**
 * @psalm-immutable
 */
final class Error extends Frame
{
    public ErrorType $type;

    public function __construct(public string $rawData)
    {
        parent::__construct(\strlen($this->rawData) + Bytes::BYTES_TYPE);

        $this->type = new ErrorType(explode(' ', $this->rawData)[0]);
    }
}
