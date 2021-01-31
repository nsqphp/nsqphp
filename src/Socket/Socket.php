<?php

declare(strict_types=1);

namespace Nsq\Socket;

use Nsq\Exception\ConnectionFail;

interface Socket
{
    /**
     * @throws ConnectionFail
     */
    public function write(string $data): void;

    /**
     * @throws ConnectionFail
     */
    public function read(int $length): string;

    /**
     * @throws ConnectionFail
     */
    public function selectRead(float $timeout): bool;

    public function close(): void;
}
