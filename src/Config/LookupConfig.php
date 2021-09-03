<?php

declare(strict_types=1);

namespace Nsq\Config;

final class LookupConfig
{
    public function __construct(
        public int $pollingInterval = 10000,
    ) {
    }
}
