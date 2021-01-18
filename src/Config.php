<?php

declare(strict_types=1);

namespace Nsq;

/**
 * @psalm-immutable
 */
final class Config
{
    public string $address;

    public function __construct(
        string $address
    ) {
        $this->address = $address;
    }
}
