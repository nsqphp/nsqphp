<?php

declare(strict_types=1);

namespace Nsq\Reconnect;

use Nsq\Exception\ConnectionFail;

interface ReconnectStrategy
{
    /**
     * @throws ConnectionFail
     */
    public function connect(callable $callable): void;
}
