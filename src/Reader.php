<?php

declare(strict_types=1);

namespace Nsq;

use Throwable;
use function sprintf;
use const PHP_EOL;

class Reader
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Subscribe to a topic/channel.
     */
    public function sub(string $topic, string $channel): void
    {
        $buffer = sprintf('SUB %s %s', $topic, $channel).PHP_EOL;

        $this->connection->write($buffer);
        $this->connection->read();
    }

    /**
     * Update RDY state (indicate you are ready to receive N messages).
     */
    public function rdy(int $count): void
    {
        $this->connection->write('RDY '.$count.PHP_EOL);
    }

    /**
     * Finish a message (indicate successful processing).
     */
    public function fin(string $id): void
    {
        $this->connection->write('FIN '.$id.PHP_EOL);
    }

    /**
     * Re-queue a message (indicate failure to process)
     * The re-queued message is placed at the tail of the queue, equivalent to having just published it,
     * but for various implementation specific reasons that behavior should not be explicitly relied upon and may change in the future.
     * Similarly, a message that is in-flight and times out behaves identically to an explicit REQ.
     */
    public function req(string $id, int $timeout): void
    {
        $this->connection->write(sprintf('REQ %s %s', $id, $timeout).PHP_EOL);
    }

    /**
     * Reset the timeout for an in-flight message.
     */
    public function touch(string $id): void
    {
        $this->connection->write('TOUCH '.$id.PHP_EOL);
    }

    public function consume(?float $timeout = null): ?Message
    {
        if (false === $this->connection->socket->selectRead($timeout)) {
            return null;
        }

        return $this->connection->read() ?? $this->consume(0);
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     */
    public function close(): void
    {
        if ($this->connection->closed) {
            return;
        }

        $this->connection->closed = true;

        $this->connection->socket->write('CLS'.PHP_EOL);
        $this->connection->read();

        try {
            $this->connection->socket->close();
        } catch (Throwable $e) {
        }
    }
}
