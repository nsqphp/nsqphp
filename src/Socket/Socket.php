<?php

declare(strict_types=1);

namespace Nsq\Socket;

use Nsq\Exception\ConnectionFail;

interface Socket
{
    public function close(): void;

    /**
     * @throws ConnectionFail
     */
    public function selectRead(float $timeout): bool;

    /**
     * @throws ConnectionFail
     */
    public function write(string $data): void;

    /**
     * @throws ConnectionFail
     */
    public function read(int $length): string;
}
