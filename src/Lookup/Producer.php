<?php

namespace Nsq\Lookup;

final class Producer
{

    public function __construct(
        public string $broadcastAddress,
        public string $hostname,
        public string $remoteAddress,
        public int $tcpPort,
        public int $httpPort,
        public string $version,
    ) {
    }

    public static function fromArray(array $array): self
    {
        return new self(
            $array['broadcast_address'],
            $array['hostname'],
            $array['remote_address'],
            $array['tcp_port'],
            $array['http_port'],
            $array['version'],
        );
    }
}
