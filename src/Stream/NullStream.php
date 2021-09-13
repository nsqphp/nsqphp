<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use Nsq\Exception\NsqException;
use Nsq\Stream;

final class NullStream implements Stream
{
    /**
     * {@inheritdoc}
     */
    public function read(): Promise
    {
        return new Success(null);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): Promise
    {
        return new Failure(new NsqException('Connection closed.'));
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
    }
}
