<?php

declare(strict_types=1);

use Nsq\Config\ClientConfig;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    /**
     * @dataProvider configs
     */
    public function test(ClientConfig $clientConfig): void
    {
        self::markTestSkipped('');
    }

    /**
     * @return Generator<string, array<int, ClientConfig>>
     */
    public function configs(): Generator
    {
        yield 'default' => [
            new ClientConfig(
                heartbeatInterval: 3000,
                snappy: false,
                readTimeout: 1,
            ),
        ];

        yield 'snappy' => [
            new ClientConfig(
                heartbeatInterval: 3000,
                snappy: true,
                readTimeout: 1,
            ),
        ];
    }
}
