<?php

declare(strict_types=1);

namespace Nsq\Protocol;

abstract class Frame
{
    public function __construct(
        /**
         * @psalm-readonly
         */
        public int $length,
    ) {
    }
}
