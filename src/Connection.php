<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Config\ClientConfig;
use Nsq\Config\ConnectionConfig;
use Nsq\Exception\AuthenticationRequired;
use Nsq\Exception\BadResponse;
use Nsq\Exception\ConnectionFail;
use Nsq\Exception\NotConnected;
use Nsq\Exception\NsqError;
use Nsq\Exception\NsqException;
use Nsq\Protocol\Error;
use Nsq\Protocol\Frame;
use Nsq\Protocol\Message;
use Nsq\Protocol\Response;
use Nsq\Socket\DeflateSocket;
use Nsq\Socket\RawSocket;
use Nsq\Socket\SnappySocket;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use function addcslashes;
use function http_build_query;
use function implode;
use const PHP_EOL;

/**
 * @internal
 */
abstract class Connection
{
    use LoggerAwareTrait;

    protected ClientConfig $clientConfig;

    private NsqSocket $socket;

    private ConnectionConfig $connectionConfig;

    private bool $closed = false;

    public function __construct(
        private string $address,
        ClientConfig $clientConfig = null,
        LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clientConfig = $clientConfig ?? new ClientConfig();

        $socket = new RawSocket($this->address, $this->logger);
        $socket->write('  V2');

        $this->socket = new NsqSocket($socket);

        $this->connectionConfig = ConnectionConfig::fromArray(
            $this
                ->command('IDENTIFY', data: $this->clientConfig->toString())
                ->readResponse()
                ->toArray()
        );

        if ($this->connectionConfig->snappy) {
            $this->socket = new NsqSocket(
                new SnappySocket(
                    $socket,
                    $this->logger,
                ),
            );

            $this->checkIsOK();
        }

        if ($this->connectionConfig->deflate) {
            $this->socket = new NsqSocket(
                new DeflateSocket(
                    $socket,
                ),
            );

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
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->command('CLS');
            $this->socket->close();
        } catch (Throwable) {
        }

        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @param array<int, int|string>|string $params
     */
    protected function command(string $command, array | string $params = [], string $data = null): self
    {
        if ($this->closed) {
            throw new NotConnected('Connection closed.');
        }

        $command = [] === $params
            ? $command
            : implode(' ', [$command, ...((array) $params)]);

        $this->logger->info('Command [{command}] with data [{data}]', ['command' => $command, 'data' => $data ?? 'null']);

        $this->socket->write($command, $data);

        return $this;
    }

    public function hasMessage(float $timeout): bool
    {
        if ($this->closed) {
            throw new NotConnected('Connection closed.');
        }

        try {
            return false !== $this->socket->wait($timeout);
        } catch (ConnectionFail $e) {
            $this->close();

            throw $e;
        }
    }

    protected function readFrame(): Frame
    {
        if ($this->closed) {
            throw new NotConnected('Connection closed.');
        }

        $buffer = $this->socket->read();

        $this->logger->debug('Received buffer: '.addcslashes($buffer->bytes(), PHP_EOL));

        return match ($type = $buffer->consumeType()) {
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
    }

    protected function checkIsOK(): void
    {
        $response = $this->readResponse();

        if ($response->isHeartBeat()) {
            $this->command('NOP');

            $this->checkIsOK();

            return;
        }

        if (!$response->isOk()) {
            throw new BadResponse($response);
        }

        $this->logger->info('Ok checked.');
    }

    private function readResponse(): Response
    {
        $frame = $this->readFrame();

        if ($frame instanceof Response) {
            return $frame;
        }

        if ($frame instanceof Error) {
            if ($frame->type->terminateConnection) {
                $this->close();
            }

            throw new NsqError($frame);
        }

        throw new NsqException('Unreachable statement.');
    }
}
