<?php

declare(strict_types=1);

namespace Nsq\Frame;

use Nsq\Exception\ServerException;
use Nsq\Frame;

/**
 * @psalm-immutable
 */
final class Error extends Frame
{
    public function __construct(public string $data)
    {
        parent::__construct(self::TYPE_ERROR);
    }

    public function toException(): ServerException
    {
        return new ServerException($this->data);
    }
}
