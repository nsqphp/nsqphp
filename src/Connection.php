<?php

declare(strict_types=1);

namespace Nsq;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ZlibInputStream;
use Amp\ByteStream\ZlibOutputStream;
use Amp\Failure;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use Nsq\Config\ClientConfig;
use Nsq\Config\ConnectionConfig;
use Nsq\Exception\AuthenticationRequired;
use Nsq\Exception\BadResponse;
use Nsq\Exception\NotConnected;
use Nsq\Exception\NsqError;
use Nsq\Exception\NsqException;
use Nsq\Protocol\Error;
use Nsq\Protocol\Frame;
use Nsq\Protocol\Message;
use Nsq\Protocol\Response;
use Nsq\Stream\NsqInputStream;
use Nsq\Stream\NullStream;
use Nsq\Stream\SnappyInputStream;
use Nsq\Stream\SnappyOutputStream;
use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\call;
use function Amp\Socket\connect;

/**
 * @internal
 */
abstract class Connection
{
    private ?Socket $socket = null;

    private InputStream $inputStream;

    private OutputStream $outputStream;

    private ByteBuffer $buffer;

    protected ?ConnectionConfig $connectionConfig = null;

    protected ClientConfig $clientConfig;

    protected LoggerInterface $logger;

    final public function __construct(
        private string $address,
        ClientConfig $clientConfig = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->buffer = new ByteBuffer();
        $this->inputStream = $this->outputStream = new NullStream();
        $this->clientConfig = $clientConfig ?? new ClientConfig();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return Promise<void>
     */
    public function connect(): Promise
    {
        return call(function (): \Generator {
            $this->socket = $this->outputStream = yield connect($this->address);
            $this->inputStream = new NsqInputStream($this->socket);

            yield $this->outputStream->write('  V2');

            yield $this->command('IDENTIFY', data: $this->clientConfig->toString());
            /** @var Response $response */
            $response = yield $this->readResponse();
            $this->connectionConfig = ConnectionConfig::fromArray($response->toArray());

            if ($this->connectionConfig->snappy) {
                $this->inputStream = new NsqInputStream(
                    new SnappyInputStream($this->inputStream, $this->logger),
                );
                $this->outputStream = new SnappyOutputStream($this->outputStream);

                $this->checkIsOK();
            }

            if ($this->connectionConfig->deflate) {
                $this->inputStream = new NsqInputStream(
                    new ZlibInputStream($this->socket, ZLIB_ENCODING_DEFLATE, [
                        'level' => $this->connectionConfig->deflateLevel,
                    ]),
                );
                $this->outputStream = new ZlibOutputStream($this->socket, ZLIB_ENCODING_DEFLATE, [
                    'level' => $this->connectionConfig->deflateLevel,
                ]);

                $this->checkIsOK();
            }

            if ($this->connectionConfig->authRequired) {
                if (null === $this->clientConfig->authSecret) {
                    yield $this->close();

                    throw new AuthenticationRequired();
                }

                yield $this->command('AUTH', data: $this->clientConfig->authSecret);
                $response = yield $this->readResponse();

                $this->logger->info('Authorization response: '.http_build_query($response->toArray()));
            }
        });
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     *
     * @return Promise<void>
     */
    public function close(): Promise
    {
        if (null === $this->socket) {
            return new Success();
        }

        return call(function (): \Generator {
            yield $this->command('CLS');

            if (null !== $this->socket) {
                $this->socket->close();

                $this->socket = null;
            }
        });
    }

    public function isClosed(): bool
    {
        return null === $this->socket;
    }

    /**
     * @param array<int, int|string>|string $params
     *
     * @return Promise<void>
     */
    protected function command(string $command, array | string $params = [], string $data = null): Promise
    {
        if (null === $this->socket) {
            return new Failure(new NotConnected());
        }

        $command = implode(' ', [$command, ...((array) $params)]);

        $buffer = $this->buffer->append($command.PHP_EOL);

        if (null !== $data) {
            $buffer->appendUint32(\strlen($data));
            $buffer->append($data);
        }

        $this->logger->debug('Sending: {bytes}', ['bytes' => $buffer->bytes()]);

        return $this->outputStream->write($buffer->flush());
    }

    /**
     * @return Promise<Frame>
     */
    protected function readFrame(): Promise
    {
        return call(function (): \Generator {
            $bytes = yield $this->inputStream->read();

            $this->logger->debug('Receiving: {bytes}', ['bytes' => $bytes]);

            if (null === $bytes) {
                throw new NotConnected();
            }

            $buffer = $this->buffer->append($bytes);

            $frame = match ($type = $buffer->consumeUint32()) {
                0 => new Response($buffer->flush()),
                1 => new Error($buffer->flush()),
                2 => new Message(
                    timestamp: $buffer->consumeInt64(),
                    attempts: $buffer->consumeUint16(),
                    id: $buffer->consume(Bytes::BYTES_ID),
                    body: $buffer->flush(),
                    consumer: $this instanceof Consumer ? $this : throw new NsqException('what?'),
                ),
                default => throw new NsqException('Unexpected frame type: '.$type)
            };

            if ($frame instanceof Response && $frame->isHeartBeat()) {
                yield $this->command('NOP');

                return $this->readFrame();
            }

            return $frame;
        });
    }

    /**
     * @return Promise<void>
     */
    protected function checkIsOK(): Promise
    {
        return call(function (): \Generator {
            /** @var Response $response */
            $response = yield $this->readResponse();

            if (!$response->isOk()) {
                throw new BadResponse($response);
            }

            $this->logger->debug('Ok checked.');

            return call(static function (): void {});
        });
    }

    /**
     * @return Promise<Response>
     */
    private function readResponse(): Promise
    {
        return call(function (): \Generator {
            $frame = yield $this->readFrame();

            if ($frame instanceof Error) {
                if ($frame->type->terminateConnection) {
                    $this->close();
                }

                throw new NsqError($frame);
            }

            if (!$frame instanceof Response) {
                throw new NsqException('Unreachable statement.');
            }

            return $frame;
        });
    }
}
