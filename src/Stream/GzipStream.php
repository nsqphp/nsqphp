<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\Promise;
use Nsq\Exception\NsqException;
use Nsq\Stream;

class GzipStream implements Stream
{
    public function __construct(private Stream $stream)
    {
        throw new NsqException('GzipStream not implemented yet.');
    }

    /**
     * {@inheritdoc}
     */
    public function read(): Promise
    {
        return $this->stream->read();
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): Promise
    {
        return $this->stream->write($data);
    }

    public function close(): void
    {
        $this->stream->close();
    }
}
