<?php

declare(strict_types=1);

namespace Nsq\Stream;

use Amp\Promise;
use Nsq\Buffer;
use Nsq\Exception\SnappyException;
use Nsq\Stream;

use function Amp\call;

class SnappyStream implements Stream
{
    private const IDENTIFIER = [0xFF, 0x06, 0x00, 0x00, 0x73, 0x4E, 0x61, 0x50, 0x70, 0x59];
    private const SIZE_HEADER = 4;
    private const SIZE_CHECKSUM = 4;
    private const SIZE_CHUNK = 65536;
    private const TYPE_IDENTIFIER = 0xFF;
    private const TYPE_COMPRESSED = 0x00;
    private const TYPE_UNCOMPRESSED = 0x01;
    private const TYPE_PADDING = 0xFE;

    private Buffer $buffer;

    public function __construct(private Stream $stream, string $bytes = '')
    {
        if (!\function_exists('snappy_uncompress') || !\function_exists('snappy_compress')) {
            throw SnappyException::notInstalled();
        }

        $this->buffer = new Buffer($bytes);
    }

    /**
     * {@inheritdoc}
     */
    public function read(): Promise
    {
        return call(function (): \Generator {
            if ($this->buffer->size() < self::SIZE_HEADER && null !== ($chunk = yield $this->stream->read())) {
                $this->buffer->append($chunk);
            }

            $type = $this->buffer->readUInt32LE();

            $size = $type >> 8;
            $type &= 0xFF;

            while ($this->buffer->size() < $size && null !== ($chunk = yield $this->stream->read())) {
                $this->buffer->append($chunk);
            }

            switch ($type) {
                case self::TYPE_IDENTIFIER:
                    $this->buffer->discard($size);

                    return $this->read();
                case self::TYPE_COMPRESSED:
                    $this->buffer->discard(self::SIZE_CHECKSUM);

                    /** @psalm-suppress UndefinedFunction */
                    return snappy_uncompress($this->buffer->consume($size - self::SIZE_HEADER));
                case self::TYPE_UNCOMPRESSED:
                    $this->buffer->discard(self::SIZE_CHECKSUM);

                    return $this->buffer->consume($size - self::SIZE_HEADER);
                case self::TYPE_PADDING:
                    return $this->read();
                default:
                    throw SnappyException::invalidHeader();
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): Promise
    {
        return call(function () use ($data): Promise {
            /** @var string $result */
            $result = pack('CCCCCCCCCC', ...self::IDENTIFIER);

            foreach (str_split($data, self::SIZE_CHUNK) as $chunk) {
                $result .= $this->compress($chunk);
            }

            return $this->stream->write($result);
        });
    }

    public function close(): void
    {
        $this->stream->close();
    }

    private function compress(string $uncompressed): string
    {
        /** @psalm-suppress UndefinedFunction */
        $compressed = snappy_compress($uncompressed);

        \assert(\is_string($compressed));

        [$type, $data] = \strlen($compressed) <= 0.875 * \strlen($uncompressed)
            ? [self::TYPE_COMPRESSED, $compressed]
            : [self::TYPE_UNCOMPRESSED, $uncompressed];

        /** @psalm-suppress PossiblyFalseArgument */
        $unpacked = unpack('N', hash('crc32c', $uncompressed, true));
        \assert(\is_array($unpacked));

        $checksum = $unpacked[1];
        $checksum = (($checksum >> 15) | ($checksum << 17)) + 0xA282EAD8 & 0xFFFFFFFF;

        $size = (\strlen($data) + 4) << 8;

        /** @psalm-suppress PossiblyFalseOperand */
        return pack('VV', $type + $size, $checksum).$data;
    }
}
