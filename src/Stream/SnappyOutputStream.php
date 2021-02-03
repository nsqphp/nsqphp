<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\ByteStream\OutputStream;
use Amp\Promise;
use PHPinnacle\Buffer\ByteBuffer;

final class SnappyOutputStream implements OutputStream
{
    private ByteBuffer $buffer;

    public function __construct(
        private OutputStream $outputStream,
    ) {
        if (!\function_exists('snappy_compress')) {
            throw new \LogicException('Snappy extension not installed.');
        }

        $this->buffer = new ByteBuffer();
    }

    /**
     * {@inheritDoc}
     *
     * @return Promise<void>
     */
    public function write(string $data): Promise
    {
        $identifierFrame = [0xff, 0x06, 0x00, 0x00, 0x73, 0x4e, 0x61, 0x50, 0x70, 0x59];
        $compressedFrame = 0x00;
        $uncompressedFrame = 0x01; // 11
        $maxChunkLength = 65536;

        $buffer = $this->buffer;
        foreach ($identifierFrame as $bite) {
            $buffer->appendUint8($bite);
        }

        foreach (str_split($data, $maxChunkLength) as $chunk) {
            $compressedChunk = snappy_compress($chunk);

            [$chunk, $chunkType] = \strlen($compressedChunk) <= 0.875 * \strlen($data)
                ? [$compressedChunk, $compressedFrame]
                : [$data, $uncompressedFrame];

            /** @var string $checksum */
            $checksum = hash('crc32c', $data, true);
            /** @phpstan-ignore-next-line */
            $checksum = unpack('N', $checksum)[1];
            $maskedChecksum = (($checksum >> 15) | ($checksum << 17)) + 0xa282ead8 & 0xffffffff;

            $size = (\strlen($chunk) + 4) << 8;

            $buffer->append(pack('V', $chunkType + $size));
            $buffer->append(pack('V', $maskedChecksum));
            $buffer->append($chunk);
        }

        return $this->outputStream->write($buffer->flush());
    }

    /**
     * {@inheritDoc}
     *
     * @return Promise<void>
     */
    public function end(string $finalData = ''): Promise
    {
        return $this->outputStream->end($finalData);
    }
}
