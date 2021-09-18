<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\Promise;
use Nsq\Buffer;
use Nsq\Exception\StreamException;
use Nsq\Stream;
use function Amp\call;

class GzipStream implements Stream
{
    /**
     * @var resource
     */
    private $inflate;

    /**
     * @var resource
     */
    private $deflate;

    private Buffer $buffer;

    public function __construct(private Stream $stream, private int $level, string $bytes = '')
    {
        $this->inflate = @inflate_init(ZLIB_ENCODING_RAW, ['level' => $this->level]);
        $this->deflate = @deflate_init(ZLIB_ENCODING_RAW, ['level' => $this->level]);

        if (false === $this->inflate) {
            throw new StreamException('Failed initializing inflate context');
        }

        if (false === $this->deflate) {
            throw new StreamException('Failed initializing deflate context');
        }

        $this->buffer = new Buffer($bytes);
    }

    /**
     * {@inheritdoc}
     */
    public function read(): Promise
    {
        return call(function (): \Generator {
            if (null === $this->inflate) {
                return null;
            }

            if ($this->buffer->empty()) {
                $chunk = yield $this->stream->read();

                if (null !== $chunk) {
                    $this->buffer->append($chunk);
                }
            }

            $data = $this->buffer->flush();

            if ('' === $data) {
                return null;
            }
            $decompressed = inflate_add($this->inflate, $data, ZLIB_SYNC_FLUSH);

            if (false === $decompressed) {
                throw new StreamException('Failed adding data to deflate context');
            }

            return $decompressed;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): Promise
    {
        if (null === $this->deflate) {
            throw new StreamException('The stream has already been closed');
        }

        $compressed = deflate_add($this->deflate, $data, ZLIB_SYNC_FLUSH);

        if (false === $compressed) {
            throw new StreamException('Failed adding data to deflate context');
        }

        return $this->stream->write($compressed);
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        $this->stream->close();
        $this->inflate = null;
        $this->deflate = null;
    }
}
