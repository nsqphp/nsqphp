<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Config\ClientConfig;
use Nsq\Config\ConnectionConfig;
use Nsq\Exception\AuthenticationRequired;
use Nsq\Exception\ConnectionFail;
use Nsq\Exception\NsqError;
use Nsq\Exception\BadResponse;
use Nsq\Exception\NsqException;
use Nsq\Exception\NullReceived;
use Nsq\Protocol\Error;
use Nsq\Protocol\Frame;
use Nsq\Protocol\Message;
use Nsq\Protocol\Response;
use Nsq\Reconnect\ExponentialStrategy;
use Nsq\Reconnect\ReconnectStrategy;
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

    private Buffer $input;

    private Buffer $output;

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

        $this->input = new Buffer();
        $this->output = new Buffer();

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

            $this->connectionConfig = ConnectionConfig::fromArray(
                $this
                    ->command('IDENTIFY', data: $body)
                    ->readResponse()
                    ->toArray()
            );

            if ($this->connectionConfig->snappy || $this->connectionConfig->deflate) {
                $this->checkIsOK();
            }

            if ($this->connectionConfig->authRequired) {
                if (null === $this->clientConfig->authSecret) {
                    throw new AuthenticationRequired();
                }

                $authResponse = $this
                    ->command('AUTH', data: $this->clientConfig->authSecret)
                    ->readResponse()
                    ->toArray()
                ;

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
        $this->output->appendCommand(
            [] === $params
                ? $command
                : implode(' ', [$command, ...((array) $params)]),
        );

        if (null !== $data) {
            $this->output->appendData($data);
        }

        $this->flush();

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

    protected function readFrame(float $timeout = null): ?Frame
    {
        $timeout ??= $this->clientConfig->readTimeout;
        $deadline = microtime(true) + $timeout;

        if (!$this->hasMessage($timeout)) {
            return null;
        }

        try {
            $buffer = $this->read(Bytes::BYTES_SIZE);

            if ('' === $buffer->bytes()) {
                $this->disconnect();

                throw new ConnectionFail('Probably connection lost');
            }

            $size = $buffer->consumeSize();

            do {
                $this->read($size);
            } while ($buffer->size() < $size);

            $this->logger->debug('Received buffer: '.addcslashes($buffer->bytes(), PHP_EOL));

            $frame = match ($type = $buffer->consumeType()) {
                0 => new Response($buffer->flush()),
                1 => new Error($buffer->flush()),
                2 => new Message(
                    timestamp: $buffer->consumeTimestamp(),
                    attempts: $buffer->consumeAttempts(),
                    id: $buffer->consumeId(),
                    body: $buffer->flush(),
                    consumer: $this instanceof Consumer ? $this : throw new NsqException('what?'),
                ),
                default => throw new NsqException('Unexpected frame type: '.$type)
            };

            if ($frame instanceof Response && $frame->isHeartBeat()) {
                $this->command('NOP');

                return $this->readFrame(
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

        return $frame;
    }

    protected function checkIsOK(): void
    {
        $response = $this->readResponse();

        if (!$response->isOk()) {
            throw new BadResponse($response);
        }
    }

    private function readResponse(): Response
    {
        $frame = $this->readFrame() ?? throw new NullReceived();

        if ($frame instanceof Response) {
            return $frame;
        }

        if ($frame instanceof Error) {
            if ($frame->type->terminateConnection) {
                $this->disconnect();
            }

            throw new NsqError($frame);
        }

        throw new NsqException('Unreachable statement.');
    }

    private function read(int $size): Buffer
    {
        return $this->input->append(
            $this->socket()->read($size),
        );
    }

    private function flush(): void
    {
        $buffer = $this->output->flush();

        $this->logger->debug('Send buffer: '.addcslashes($buffer, PHP_EOL));

        try {
            $this->socket()->write(
                $buffer,
            );
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            $this->logger->error($e->getMessage(), ['exception' => $e]);

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
    }

    private function socket(): Socket
    {
        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket ?? throw new ConnectionFail('This connection is closed, create new one.');
    }
}
