<?php

declare(strict_types=1);

namespace Nsq\Reconnect;

final class RealTimeProvider implements TimeProvider
{
    public function time(): int
    {
        return time();
    }
}
