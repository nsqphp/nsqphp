<?php

declare(strict_types=1);

namespace Nsq;

use Amp\ByteStream\ClosedException;
use Amp\Promise;

interface Stream
{
    /**
     * @psalm-return Promise<null|string>
     */
    public function read(): Promise;

    /**
     * @psalm-return Promise<void>
     */
    public function write(string $data): Promise;

    /**
     * @throws ClosedException
     */
    public function close(): void;
}
