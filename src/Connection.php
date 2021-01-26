<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Config\ClientConfig;
use Nsq\Config\ConnectionConfig;
use Nsq\Exception\AuthenticationRequired;
use Nsq\Exception\ConnectionFail;
use Nsq\Exception\UnexpectedResponse;
use Nsq\Reconnect\ExponentialStrategy;
use Nsq\Reconnect\ReconnectStrategy;
use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket\Raw\Exception;
use Socket\Raw\Factory;
use Socket\Raw\Socket;
use function addcslashes;
use function hash;
use function http_build_query;
use function implode;
use function json_encode;
use function pack;
use function snappy_compress;
use function unpack;
use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

/**
 * @internal
 *
 * @property ConnectionConfig $connectionConfig
 */
abstract class Connection
{
    use LoggerAwareTrait;

    private string $address;

    private ?Socket $socket = null;

    private ReconnectStrategy $reconnect;

    private ClientConfig $clientConfig;

    private ?ConnectionConfig $connectionConfig = null;

    public function __construct(
        string $address,
        ClientConfig $clientConfig = null,
        ReconnectStrategy $reconnectStrategy = null,
        LoggerInterface $logger = null,
    ) {
        $this->address = $address;

        $this->logger = $logger ?? new NullLogger();
        $this->reconnect = $reconnectStrategy ?? new ExponentialStrategy(logger: $this->logger);
        $this->clientConfig = $clientConfig ?? new ClientConfig();
    }

    public function connect(): void
    {
        $this->reconnect->connect(function (): void {
            try {
                $this->socket = (new Factory())->createClient($this->address);
            }
            // @codeCoverageIgnoreStart
            catch (Exception $e) {
                $this->logger->error('Connecting to {address} failed.', ['address' => $this->address]);

                throw ConnectionFail::fromThrowable($e);
            }
            // @codeCoverageIgnoreEnd

            $this->socket->write('  V2');

            $body = json_encode($this->clientConfig, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

            $response = $this->command('IDENTIFY', data: $body)->response();

            $this->connectionConfig = ConnectionConfig::fromArray($response->toArray());

            if ($this->connectionConfig->snappy || $this->connectionConfig->deflate) {
                $this->response()->okOrFail();
            }

            if ($this->connectionConfig->authRequired) {
                if (null === $this->clientConfig->authSecret) {
                    throw new AuthenticationRequired('NSQ requires authorization, set ClientConfig::$authSecret before connecting');
                }

                $authResponse = $this->command('AUTH', data: $this->clientConfig->authSecret)->response()->toArray();

                $this->logger->info('Authorization response: '.http_build_query($authResponse));
            }
        });
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     */
    public function disconnect(): void
    {
        if (null === $this->socket) {
            return;
        }

        try {
            $this->socket->write('CLS'.PHP_EOL);
            $this->socket->close();
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->logger->debug($e->getMessage(), ['exception' => $e]);
        }
        // @codeCoverageIgnoreEnd

        $this->socket = null;
        $this->connectionConfig = null;
    }

    public function isReady(): bool
    {
        return null !== $this->socket;
    }

    /**
     * @param array<int, int|string>|string $params
     */
    protected function command(string $command, array | string $params = [], string $data = null): self
    {
        $socket = $this->socket();

        $buffer = [] === $params ? $command : implode(' ', [$command, ...((array) $params)]);
        $buffer .= PHP_EOL;

        if (null !== $data) {
            $buffer .= pack('N', \strlen($data));
            $buffer .= $data;
        }

        $this->logger->debug('Prepare send uncompressed buffer: {bytes}', ['bytes' => addcslashes($buffer, PHP_EOL)]);

        if ($this->connectionConfig?->snappy) {
            $identifierFrame = [0xff, 0x06, 0x00, 0x00, 0x73, 0x4e, 0x61, 0x50, 0x70, 0x59];
            $compressedFrame = 0x00;
            $uncompressedFrame = 0x01;

            $chunk = snappy_compress($buffer);
            [$chunk, $compressFrame] = match (\strlen($chunk) < \strlen($buffer)) {
                true => [$chunk, $compressedFrame],
                false => [$buffer, $uncompressedFrame],
            };

            $size = \strlen($chunk) + 4;

            $buffer = new ByteBuffer();
            foreach ([...$identifierFrame, $compressFrame, $size, $size >> 8, $size >> 16] as $byte) {
                $buffer->appendUint8($byte);
            }

            $crc32c = hash('crc32c', $data, true);
            $crc32c = unpack('V', $crc32c)[1];

            $unsignedRightShift = static function ($a, $b) {
                if ($b >= 32 || $b < -32) {
                    $m = (int) ($b / 32);
                    $b -= ($m * 32);
                }

                if ($b < 0) {
                    $b = 32 + $b;
                }

                if (0 === $b) {
                    return (($a >> 1) & 0x7fffffff) * 2 + (($a >> $b) & 1);
                }

                if ($a < 0) {
                    $a >>= 1;
                    $a &= 2147483647;
                    $a |= 0x40000000;
                    $a >>= ($b - 1);
                } else {
                    $a >>= $b;
                }

                return $a;
            };
            $checksum = $unsignedRightShift((($crc32c >> 15) | ($crc32c << 17)) + 0xa282ead8, 0);

            $buffer->appendUint32($checksum);
            $buffer->append($chunk);

            $buffer = $buffer->bytes();
        }

        $this->logger->debug('Prepare send compressed buffer: {bytes}', ['bytes' => addcslashes($buffer, PHP_EOL)]);

        try {
            $socket->write($buffer);
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            $this->logger->error($e->getMessage(), ['exception' => $e]);

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
        return $this;
    }

    public function hasMessage(float $timeout = 0): bool
    {
        try {
            return false !== $this->socket()->selectRead($timeout);
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
    }

    public function receive(float $timeout = null): ?Response
    {
        $socket = $this->socket();

        $timeout ??= $this->clientConfig->readTimeout;
        $deadline = microtime(true) + $timeout;

        if (!$this->hasMessage($timeout)) {
            return null;
        }

        try {
            $size = $socket->read(Bytes::BYTES_SIZE);

            if ('' === $size) {
                $this->disconnect();

                throw new ConnectionFail('Probably connection lost');
            }

            if ($this->connectionConfig?->snappy) {
                $buffer = new ByteBuffer();
                $snappyBuffer = new ByteBuffer($size);
                while (true) {
                    $typeByte = \ord($snappyBuffer->consume(1));

                    $size = \ord($snappyBuffer->consume(1)) + (\ord($snappyBuffer->consume(1)) << 8) + (\ord($snappyBuffer->consume(1)) << 16);
                    $type = match ($typeByte) {
                        0xff => 'identifier',
                        0x00 => 'compressed',
                        0x01 => 'uncompressed',
                        0xfe => 'padding',
                    };

                    $this->logger->debug('Received snappy chunk: {type}, size: {size}', [
                        'type' => $type,
                        'size' => $size,
                    ]);

                    switch ($typeByte) {
                        case 0xff: // 'identifier',
                            $socket->read($size);
                            $snappyBuffer->append($socket->read(4));

                            continue 2;
                        case 0x00: // 'compressed',
                        case 0x01: // 'uncompressed',
                            $uncompressed = $socket->read($size);

                            $this->logger->debug('Received uncompressed bytes: {bytes}', ['bytes' => $uncompressed]);
                            $buffer->append($uncompressed);
                            $buffer->consume(4); // slice snappy prefix
                            $buffer->consumeUint32(); // slice size

                            break 2;
                        case 0xfe:// 'padding',
                    }
                }
            } else {
                $this->logger->debug('Size bytes received: "{bytes}"', ['bytes' => $size]);

                $buffer = new ByteBuffer($size);

                $size = $buffer->consumeUint32();

                do {
                    $chunk = $socket->read($size);

                    $buffer->append($chunk);

                    $size -= \strlen($chunk);
                } while (0 < $size);
            }

            $this->logger->debug('Received buffer: '.addcslashes($buffer->bytes(), PHP_EOL));

            $response = new Response($buffer);

            if ($response->isHeartBeat()) {
                $this->command('NOP');

                return $this->receive(
                    ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime
                );
            }
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd

        return $response;
    }

    protected function response(): Response
    {
        return $this->receive() ?? throw UnexpectedResponse::null();
    }

    private function socket(): Socket
    {
        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket ?? throw new ConnectionFail('This connection is closed, create new one.');
    }
}
