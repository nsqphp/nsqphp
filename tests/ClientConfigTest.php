<?php

declare(strict_types=1);

use Nsq\Config\ClientConfig;
use PHPUnit\Framework\TestCase;

final class ClientConfigTest extends TestCase
{
    public function testNegotiationPayload(): void
    {
        self::assertJson((new ClientConfig())->asNegotiationPayload());
    }

    public function testInvalidCompression(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client cannot enable both [snappy] and [deflate]');

        new ClientConfig(deflate: true, snappy: true);
    }

    /**
     * @dataProvider array
     */
    public function testFromArray(array $data, array $expected): void
    {
        self::assertSame($expected, get_object_vars(ClientConfig::fromArray($data)));
    }

    public function array(): Generator
    {
        $default = get_object_vars(new ClientConfig());

        yield 'Empty array' => [[], $default];

        yield 'With wrong keys' => [['bla' => 'bla'], $default];

        $custom = [
            'authSecret' => 'SomeSecret',
            'connectTimeout' => 100,
            'maxAttempts' => 10,
            'tcpNoDelay' => true,
            'rdyCount' => 1,
            'featureNegotiation' => true,
            'clientId' => 'SomeGorgeousClientId',
            'deflate' => true,
            'deflateLevel' => 1,
            'heartbeatInterval' => 31111,
            'hostname' => gethostname(),
            'msgTimeout' => 59999,
            'sampleRate' => 25,
            'tls' => true,
            'snappy' => false,
            'userAgent' => 'nsqphp/test',
        ];

        yield 'Full filled' => [$custom, $custom];
    }
}
