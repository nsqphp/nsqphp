<?php

declare(strict_types=1);

namespace Nsq;

use Amp\ByteStream\ClosedException;
use Amp\Failure;
use Amp\Promise;
use Nsq\Config\ClientConfig;
use Nsq\Config\ServerConfig;
use Nsq\Exception\AuthenticationRequired;
use Nsq\Exception\NsqException;
use Nsq\Frame\Response;
use Nsq\Stream\GzipStream;
use Nsq\Stream\NullStream;
use Nsq\Stream\SnappyStream;
use Nsq\Stream\SocketStream;
use Psr\Log\LoggerInterface;

use function Amp\asyncCall;
use function Amp\call;

/**
 * @internal
 */
abstract class Connection
{
    private Stream $stream;

    /**
     * @var callable
     */
    private $onConnectCallback;

    /**
     * @var callable
     */
    private $onCloseCallback;

    public function __construct(
        /**
         * @readonly
         */
        public string $address,
        protected ClientConfig $clientConfig,
        protected LoggerInterface $logger,
    ) {
        $this->stream = new NullStream();
        $this->onConnectCallback = static function (): void {
        };
        $this->onCloseCallback = static function (): void {
        };
    }

    public function __destruct()
    {
        $this->close(false);
    }

    public function isConnected(): bool
    {
        return !$this->stream instanceof NullStream;
    }

    /**
     * @psalm-return Promise<void>
     */
    public function connect(): Promise
    {
        return call(function (): \Generator {
            $buffer = new Buffer();

            /** @var SocketStream $stream */
            $stream = yield SocketStream::connect(
                $this->address,
                $this->clientConfig->connectTimeout,
                $this->clientConfig->maxAttempts,
                $this->clientConfig->tcpNoDelay,
            );

            yield $stream->write(Command::magic());
            yield $stream->write(Command::identify($this->clientConfig->asNegotiationPayload()));

            /** @var Response $response */
            $response = yield $this->response($stream, $buffer);
            $serverConfig = ServerConfig::fromArray($response->toArray());

            if ($serverConfig->tls) {
                yield $stream->setupTls();

                /** @var Response $response */
                $response = yield $this->response($stream, $buffer);

                if (!$response->isOk()) {
                    throw new NsqException();
                }
            }

            if ($serverConfig->snappy) {
                $stream = new SnappyStream($stream, $buffer->flush());

                /** @var Response $response */
                $response = yield $this->response($stream, $buffer);

                if (!$response->isOk()) {
                    throw new NsqException();
                }
            }

            if ($serverConfig->deflate) {
                $stream = new GzipStream($stream, $serverConfig->deflateLevel, $buffer->flush());

                /** @var Response $response */
                $response = yield $this->response($stream, $buffer);

                if (!$response->isOk()) {
                    throw new NsqException();
                }
            }

            if ($serverConfig->authRequired) {
                if (null === $this->clientConfig->authSecret) {
                    throw new AuthenticationRequired();
                }

                yield $stream->write(Command::auth($this->clientConfig->authSecret));

                /** @var Response $response */
                $response = yield $this->response($stream, $buffer);

                $this->logger->info('Authorization response: '.http_build_query($response->toArray()));
            }

            $this->stream = $stream;

            ($this->onConnectCallback)();
        });
    }

    public function close(bool $graceful = true): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $logger = $this->logger;
        [$stream, $this->stream] = [$this->stream, new NullStream()];

        if ($graceful) {
            $this->logger->debug('Graceful disconnect.', [
                'class' => static::class,
                'address' => $this->address,
            ]);

            asyncCall(static function () use ($stream, $logger): \Generator {
                try {
                    yield $stream->write(Command::cls());
                } catch (\Throwable $e) {
                    $logger->warning($e->getMessage(), ['exception' => $e]);
                }

                $stream->close();
            });

            return;
        }

        try {
            $stream->close();
        } catch (ClosedException) {
        }

        ($this->onCloseCallback)();
    }

    public function onConnect(callable $callback): static
    {
        $previous = $this->onConnectCallback;
        $this->onConnectCallback = static function () use ($previous, $callback): void {
            $previous();
            $callback();
        };

        return $this;
    }

    public function onClose(callable $callback): static
    {
        $previous = $this->onCloseCallback;
        $this->onCloseCallback = static function () use ($previous, $callback): void {
            $previous();
            $callback();
        };

        return $this;
    }

    /**
     * @psalm-return Promise<null|string>
     */
    protected function read(): Promise
    {
        return call(function (): \Generator {
            try {
                return yield $this->stream->read();
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);

                $this->close(false);

                return new Failure($e);
            }
        });
    }

    /**
     * @psalm-return Promise<void>
     */
    protected function write(string $data): Promise
    {
        return call(function () use ($data): \Generator {
            try {
                return yield $this->stream->write($data);
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);

                $this->close(false);

                return new Failure($e);
            }
        });
    }

    protected function handleError(Frame\Error $error): void
    {
        $this->logger->error($error->data);

        if (ErrorType::terminable($error)) {
            $this->close();

            throw $error->toException();
        }
    }

    /**
     * @psalm-return Promise<Frame\Response>
     */
    private function response(Stream $stream, Buffer $buffer): Promise
    {
        return call(function () use ($stream, $buffer): \Generator {
            while (true) {
                $response = Parser::parse($buffer);

                if (null === $response && null !== ($chunk = yield $stream->read())) {
                    $buffer->append($chunk);

                    continue;
                }

                if (!$response instanceof Frame\Response) {
                    throw new NsqException();
                }

                return $response;
            }
        });
    }
}
