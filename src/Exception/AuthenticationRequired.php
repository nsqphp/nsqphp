<?php

declare(strict_types=1);

namespace Nsq\Exception;

final class AuthenticationRequired extends NsqException
{
    public function __construct()
    {
        parent::__construct('NSQ requires authorization, set ClientConfig::$authSecret before connecting');
    }
}
