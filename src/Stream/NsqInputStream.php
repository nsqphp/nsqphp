<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\ByteStream\InputStream;
use Amp\Promise;
use Nsq\Bytes;
use Nsq\Exception\NotConnected;
use PHPinnacle\Buffer\ByteBuffer;
use function Amp\call;

final class NsqInputStream implements InputStream
{
    private ByteBuffer $buffer;

    public function __construct(
        private InputStream $inputStream,
    ) {
        $this->buffer = new ByteBuffer();
    }

    /**
     * {@inheritDoc}
     */
    public function read(): Promise
    {
        return call(function (): \Generator {
            $buffer = $this->buffer;

            while ($buffer->size() < Bytes::BYTES_SIZE) {
                $bytes = yield $this->inputStream->read();

                if (null === $bytes) {
                    throw new NotConnected();
                }

                $buffer->append($bytes);
            }

            $size = $buffer->consumeUint32();

            while ($buffer->size() < $size) {
                $bytes = yield $this->inputStream->read();

                if (null === $bytes) {
                    throw new NotConnected();
                }

                $buffer->append($bytes);
            }

            return $buffer->consume($size);
        });
    }
}
