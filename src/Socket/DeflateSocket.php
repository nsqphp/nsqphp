<?php

declare(strict_types=1);

namespace Nsq\Socket;

final class DeflateSocket implements Socket
{
    public function __construct(
        private Socket $socket,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $data): void
    {
        throw new \LogicException('not implemented.');
    }

    /**
     * {@inheritDoc}
     */
    public function read(int $length): string
    {
        throw new \LogicException('not implemented.');
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        throw new \LogicException('not implemented.');
    }

    /**
     * {@inheritDoc}
     */
    public function selectRead(float $timeout): bool
    {
        return $this->socket->selectRead($timeout);
    }
}
