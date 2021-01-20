<?php

declare(strict_types=1);

namespace Nsq;

use LogicException;
use PHPinnacle\Buffer\ByteBuffer;
use Socket\Raw\Factory;
use Socket\Raw\Socket;
use Throwable;
use function json_encode;
use function microtime;
use function pack;
use function sprintf;
use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

/**
 * @internal
 */
abstract class Connection
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

    public ?Socket $socket = null;

    public bool $closed = false;

    private Config $config;

    public function __construct(string $address)
    {
        $this->config = new Config($address);
    }

    /**
     * @psalm-suppress UnsafeInstantiation
     */
    public function connect(): void
    {
        $this->socket = (new Factory())->createClient($this->config->address);
        $this->socket->write(self::MAGIC_V2);
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     */
    public function disconnect(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->write('CLS'.PHP_EOL);
            $this->consume(); // receive CLOSE_WAIT

            if (null !== $this->socket) {
                $this->socket->close();
            }
        } catch (Throwable $e) {
            // Not interested
        }

        $this->closed = true;
    }

    /**
     * @psalm-param array<string, string|numeric> $arr
     *
     * @psalm-suppress PossiblyFalseOperand
     */
    protected function identify(array $arr): string
    {
        $body = json_encode($arr, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        $size = pack('N', \strlen($body));

        return 'IDENTIFY '.PHP_EOL.$size.$body;
    }

    /**
     * @psalm-suppress PossiblyFalseOperand
     */
    protected function auth(string $secret): string
    {
        $size = pack('N', \strlen($secret));

        return 'AUTH'.PHP_EOL.$size.$secret;
    }

    /**
     * @internal
     */
    public function write(string $buffer): void
    {
        $socket = $this->socket();

        try {
            $socket->write($buffer);
        } catch (Throwable $e) {
            $this->closed = true;

            throw $e;
        }
    }

    protected function consume(?float $timeout = 0): ?Message
    {
        $deadline = microtime(true) + ($timeout ?? 0);

        $socket = $this->socket();

        if (false === $socket->selectRead($timeout)) {
            return null;
        }

        $buffer = new ByteBuffer($socket->read(self::BYTES_SIZE + self::BYTES_TYPE));
        $size = $buffer->consumeUint32();
        $type = $buffer->consumeUint32();

        $buffer->append($socket->read($size - self::BYTES_TYPE));

        if (self::TYPE_RESPONSE === $type) {
            $response = $buffer->consume($size - self::BYTES_TYPE);

            $isInternalMessage = false;
            if (self::OK === $response || self::CLOSE_WAIT === $response) {
                $isInternalMessage = true;
            }

            if (self::HEARTBEAT === $response) {
                $socket->write('NOP'.PHP_EOL);

                $isInternalMessage = true;
            }

            if ($isInternalMessage) {
                return $this->consume(
                    ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime
                );
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

    private function socket(): Socket
    {
        if ($this->closed) {
            throw new LogicException('This connection is closed, create new one.');
        }

        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket;
    }
}
