<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\Failure;
use Amp\Promise;
use Nsq\Exception\NotConnected;

final class NullStream implements InputStream, OutputStream
{
    /**
     * {@inheritDoc}
     */
    public function read(): Promise
    {
        return new Failure(new NotConnected());
    }

    /**
     * {@inheritDoc}
     *
     * @return Promise<void>
     */
    public function write(string $data): Promise
    {
        return new Failure(new NotConnected());
    }

    /**
     * {@inheritDoc}
     *
     * @return Promise<void>
     */
    public function end(string $finalData = ''): Promise
    {
        return new Failure(new NotConnected());
    }
}
