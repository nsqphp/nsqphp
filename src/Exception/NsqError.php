<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Nsq\Protocol\Error;

final class NsqError extends NsqException
{
    public function __construct(Error $error)
    {
        parent::__construct($error->rawData);
    }
}
