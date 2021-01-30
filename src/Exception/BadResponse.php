<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Nsq\Protocol\Response;

final class BadResponse extends NsqException
{
    public function __construct(Response $response)
    {
        parent::__construct($response->msg);
    }
}
