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
use function http_build_query;
use function implode;
use function json_encode;
use function pack;
use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

/**
 * @internal
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

            if ($this->clientConfig->featureNegotiation) {
                $this->connectionConfig = ConnectionConfig::fromArray($response->toArray());
            }

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

        $this->logger->debug('Send buffer: '.addcslashes($buffer, PHP_EOL));

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

            $buffer = new ByteBuffer(
                $socket->read(
                // @phpstan-ignore-next-line
                    unpack('N', $size)[1]
                )
            );

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
