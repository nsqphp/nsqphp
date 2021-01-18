<?php

declare(strict_types=1);

namespace Nsq;

use LogicException;
use PHPinnacle\Buffer\ByteBuffer;
use Socket\Raw\Factory;
use Socket\Raw\Socket;
use Throwable;
use function json_encode;
use function pack;
use function sprintf;
use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

final class Connection
{
    private const OK = 'OK';
    private const HEARTBEAT = '_heartbeat_';
    private const CLOSE_WAIT = 'CLOSE_WAIT';
    private const TYPE_RESPONSE = 0;
    private const TYPE_ERROR = 1;
    private const TYPE_MESSAGE = 2;
    private const BYTES_SIZE = 4;
    private const BYTES_TYPE = 4;
    private const BYTES_ATTEMPTS = 2;
    private const BYTES_TIMESTAMP = 8;
    private const BYTES_ID = 16;
    private const MAGIC_V2 = '  V2';

    public Socket $socket;

    public bool $closed = false;

    private function __construct(Socket $socket)
    {
        $this->socket = $socket;
    }

    /**
     * @psalm-suppress UnsafeInstantiation
     *
     * @return static
     */
    public static function connect(Config $config): self
    {
        $socket = (new Factory())->createClient($config->address);
        $socket->write(self::MAGIC_V2);

        // @phpstan-ignore-next-line
        return new self($socket);
    }

    /**
     * @psalm-param array<string, string|numeric> $arr
     *
     * @psalm-suppress PossiblyFalseOperand
     */
    public function identify(array $arr): string
    {
        $body = json_encode($arr, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        $size = pack('N', \strlen($body));

        return 'IDENTIFY '.PHP_EOL.$size.$body;
    }

    /**
     * @psalm-suppress PossiblyFalseOperand
     */
    public function auth(string $secret): string
    {
        $size = pack('N', \strlen($secret));

        return 'AUTH'.PHP_EOL.$size.$secret;
    }

    public function write(string $buffer): void
    {
        if ($this->closed) {
            throw new LogicException('This connection is closed, create new one.');
        }

        try {
            $this->socket->write($buffer);
        } catch (Throwable $e) {
            $this->closed = true;

            throw $e;
        }
    }

    public function read(): ?Message
    {
        $socket = $this->socket;

        $buffer = new ByteBuffer($socket->read(self::BYTES_SIZE + self::BYTES_TYPE));
        $size = $buffer->consumeUint32();
        $type = $buffer->consumeUint32();

        $buffer->append($socket->read($size - self::BYTES_TYPE));

        if (self::TYPE_RESPONSE === $type) {
            $response = $buffer->consume($size - self::BYTES_TYPE);

            if (self::OK === $response || self::CLOSE_WAIT === $response) {
                return null;
            }

            if (self::HEARTBEAT === $response) {
                $socket->write('NOP'.PHP_EOL);

                return null;
            }

            throw new LogicException(sprintf('Unexpected response from nsq: "%s"', $response));
        }

        if (self::TYPE_ERROR === $type) {
            throw new LogicException(sprintf('NSQ return error: "%s"', $socket->read($size)));
        }

        if (self::TYPE_MESSAGE !== $type) {
            throw new LogicException(sprintf('Expecting "%s" type, but NSQ return: "%s"', self::TYPE_MESSAGE, $type));
        }

        $timestamp = $buffer->consumeInt64();
        $attempts = $buffer->consumeUint16();
        $id = $buffer->consume(self::BYTES_ID);
        $body = $buffer->consume($size - self::BYTES_TYPE - self::BYTES_TIMESTAMP - self::BYTES_ATTEMPTS - self::BYTES_ID);

        return new Message($timestamp, $attempts, $id, $body);
    }
}
