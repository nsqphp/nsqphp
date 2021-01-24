<?php

declare(strict_types=1);

namespace Nsq\Reconnect;

interface TimeProvider
{
    public function time(): int;
}
