<?php

declare(strict_types=1);

use Nsq\Config\ClientConfig;
use PHPUnit\Framework\TestCase;

final class ClientConfigTest extends TestCase
{
    public function testInvalidCompression(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client cannot enable both [snappy] and [deflate]');

        new ClientConfig(deflate: true, snappy: true);
    }
}
