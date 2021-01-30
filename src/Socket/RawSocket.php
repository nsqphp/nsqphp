<?php

declare(strict_types=1);

namespace Nsq\Socket;

use Nsq\Exception\ConnectionFail;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket\Raw\Exception;
use Socket\Raw\Factory;
use Socket\Raw\Socket as ClueSocket;
use Throwable;

final class RawSocket implements Socket
{
    private ClueSocket $socket;

    private LoggerInterface $logger;

    public function __construct(string $address, LoggerInterface $logger = null)
    {
        $this->socket = (new Factory())->createClient($address);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritDoc}
     */
    public function selectRead(float $timeout): bool
    {
        try {
            return false !== $this->socket->selectRead($timeout);
        } // @codeCoverageIgnoreStart
        catch (Exception $e) {
            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        try {
            $this->socket->close();
        } catch (Throwable) {
        }
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $data): void
    {
        try {
            $this->socket->write($data);
        } // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * {@inheritDoc}
     */
    public function read(int $length): string
    {
        try {
            return $this->socket->read($length);
        } // @codeCoverageIgnoreStart
        catch (Exception $e) {
            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
    }
}
