<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Exception\NsqError;
use Nsq\Exception\UnexpectedResponse;
use PHPinnacle\Buffer\ByteBuffer;
use function json_decode;
use function sprintf;
use const JSON_THROW_ON_ERROR;

final class Response
{
    private const OK = 'OK';
    private const HEARTBEAT = '_heartbeat_';
    private const TYPE_RESPONSE = 0;
    private const TYPE_ERROR = 1;
    private const TYPE_MESSAGE = 2;

    private int $type;

    private ByteBuffer $buffer;

    public function __construct(ByteBuffer $buffer)
    {
        $this->type = $buffer->consumeUint32();
        $this->buffer = $buffer;
    }

    public function okOrFail(): void
    {
        if (self::TYPE_ERROR === $this->type) {
            throw new NsqError($this->buffer->bytes());
        }

        if (self::TYPE_RESPONSE !== $this->type) {
            // @codeCoverageIgnoreStart
            throw new UnexpectedResponse(sprintf('"%s" type expected, but "%s" received.', self::TYPE_RESPONSE, $this->type));
            // @codeCoverageIgnoreEnd
        }

        if (self::OK !== $this->buffer->bytes()) {
            // @codeCoverageIgnoreStart
            throw new UnexpectedResponse(sprintf('OK response expected, but "%s" received.', $this->buffer->bytes()));
            // @codeCoverageIgnoreEnd
        }
    }

    public function isHeartBeat(): bool
    {
        return self::TYPE_RESPONSE === $this->type && self::HEARTBEAT === $this->buffer->bytes();
    }

    /**
     * @phpstan-ignore-next-line
     */
    public function toArray(): array
    {
        if (self::TYPE_RESPONSE !== $this->type) {
            // @codeCoverageIgnoreStart
            throw new UnexpectedResponse(sprintf('"%s" type expected, but "%s" received.', self::TYPE_RESPONSE, $this->type));
            // @codeCoverageIgnoreEnd
        }

        return json_decode($this->buffer->bytes(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function toMessage(Consumer $reader): Message
    {
        if (self::TYPE_MESSAGE !== $this->type) {
            // @codeCoverageIgnoreStart
            throw new UnexpectedResponse(sprintf('Expecting "%s" type, but NSQ return: "%s"', self::TYPE_MESSAGE, $this->type));
            // @codeCoverageIgnoreEnd
        }

        $buffer = new ByteBuffer($this->buffer->bytes());

        $timestamp = $buffer->consumeInt64();
        $attempts = $buffer->consumeUint16();
        $id = $buffer->consume(Bytes::BYTES_ID);
        $body = $buffer->flush();

        return new Message($timestamp, $attempts, $id, $body, $reader);
    }
}
