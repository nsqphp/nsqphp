<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\ByteStream\InputStream;
use Amp\Promise;
use Nsq\Exception\NotConnected;
use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerInterface;
use function Amp\call;

final class SnappyInputStream implements InputStream
{
    private ByteBuffer $buffer;

    public function __construct(
        private InputStream $inputStream,
        private LoggerInterface $logger,
    ) {
        if (!\function_exists('snappy_uncompress')) {
            throw new \LogicException('Snappy extension not installed.');
        }

        $this->buffer = new ByteBuffer();
    }

    /**
     * {@inheritDoc}
     */
    public function read(): Promise
    {
        return call(function (): \Generator {
            $buffer = $this->buffer;

            while ($buffer->size() < 4) {
                $bytes = yield $this->inputStream->read();

                if (null === $bytes) {
                    throw new NotConnected();
                }

                $buffer->append($bytes);
            }

            /** @phpstan-ignore-next-line */
            $chunkType = unpack('V', $buffer->consume(4))[1];

            $size = $chunkType >> 8;
            $chunkType &= 0xff;

            $this->logger->debug('Snappy receive chunk [{chunk}], size [{size}]', [
                'chunk' => $chunkType,
                'size' => $size,
            ]);

            while ($buffer->size() < $size) {
                $bytes = yield $this->inputStream->read();

                if (null === $bytes) {
                    throw new NotConnected();
                }

                $buffer->append($bytes);
            }

            switch ($chunkType) {
                case 0xff:
                    $this->logger->debug('Snappy identifier chunk');

                    $buffer->discard(6); // discard identifier body

                break;
                case 0x00: // 'compressed',
                    $this->logger->debug('Snappy compressed chunk');

                    $data = $buffer
                        ->discard(4) // discard checksum
                        ->consume($size)
                    ;

                    $this->logger->debug('Snappy compressed data [{data}]', ['data' => $data]);

                    return snappy_uncompress($data);
                case 0x01: // 'uncompressed',
                    $this->logger->debug('Snappy uncompressed chunk');

                    $data = $buffer
                        ->discard(4) // discard checksum
                        ->consume($size)
                    ;

                    $this->logger->debug('Snappy uncompressed data [{data}]', ['data' => $data]);

                    return $data;
                case 0xfe:// 'padding',
                    $this->logger->debug('Snappy padding chunk');

                    $buffer->discard($size); // TODO ?
            }

            return $this->read();
        });
    }
}
