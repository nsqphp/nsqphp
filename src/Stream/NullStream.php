<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\Promise;
use Amp\Success;
use Nsq\Stream;
use function Amp\call;

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
        return call(static function (): void {
        });
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
    }
}
