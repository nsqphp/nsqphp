<?php

declare(strict_types=1);

namespace Nsq;

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

/**
 * @internal
 */
abstract class Connection
{
    protected const OK = 'OK';
    protected const HEARTBEAT = '_heartbeat_';
    protected const CLOSE_WAIT = 'CLOSE_WAIT';
    protected const TYPE_RESPONSE = 0;
    protected const TYPE_ERROR = 1;
    protected const TYPE_MESSAGE = 2;
    protected const BYTES_SIZE = 4;
    protected const BYTES_TYPE = 4;
    protected const BYTES_ATTEMPTS = 2;
    protected const BYTES_TIMESTAMP = 8;
    protected const BYTES_ID = 16;
    private const MAGIC_V2 = '  V2';

    public ?Socket $socket = null;

    private bool $closed = false;

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
            $this->send('CLS'.PHP_EOL)->expectResponse(self::CLOSE_WAIT);

            if (null !== $this->socket) {
                $this->socket->close();
            }
        } catch (Throwable $e) {
            // Not interested
        }

        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
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

    protected function send(string $buffer): self
    {
        $socket = $this->socket();

        try {
            $socket->write($buffer);
        } catch (Throwable $e) {
            $this->closed = true;

            throw $e;
        }

        return $this;
    }

    protected function receive(float $timeout = 0): ?ByteBuffer
    {
        $socket = $this->socket();

        if (false === $socket->selectRead($timeout)) {
            return null;
        }

        $size = (new ByteBuffer($socket->read(self::BYTES_SIZE)))->consumeUint32();

        return new ByteBuffer($socket->read($size));
    }

    protected function expectResponse(string $expected): void
    {
        $buffer = $this->receive(0.1);
        if (null === $buffer) {
            throw new Exception('Success response was expected, but null received.');
        }

        $type = $buffer->consumeUint32();
        $response = $buffer->flush();

        if (self::TYPE_ERROR === $type) {
            throw new Exception($response);
        }

        if (self::TYPE_RESPONSE !== $type) {
            throw new Exception(sprintf('"%s" type expected, but "%s" received.', self::TYPE_RESPONSE, $type));
        }

        if ($expected !== $response) {
            throw new Exception(sprintf('"%s" response expected, but "%s" received.', $expected, $response));
        }
    }

    private function socket(): Socket
    {
        if ($this->closed) {
            throw new Exception('This connection is closed, create new one.');
        }

        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket;
    }
}
