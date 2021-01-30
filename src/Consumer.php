<?php

declare(strict_types=1);

namespace Nsq;

use Generator;
use Nsq\Config\ClientConfig;
use Nsq\Exception\NsqError;
use Nsq\Exception\NsqException;
use Nsq\Protocol\Error;
use Nsq\Protocol\Message;
use Nsq\Reconnect\ReconnectStrategy;
use Psr\Log\LoggerInterface;

final class Consumer extends Connection
{
    private int $rdy = 0;

    public function __construct(
        private string $topic,
        private string $channel,
        string $address,
        ClientConfig $clientConfig = null,
        ReconnectStrategy $reconnectStrategy = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($address, $clientConfig, $reconnectStrategy, $logger);
    }

    public function connect(): void
    {
        parent::connect();

        $this->command('SUB', [$this->topic, $this->channel])->checkIsOK();
    }

    /**
     * @psalm-return Generator<int, Message|float|null, int|null, void>
     */
    public function generator(): Generator
    {
        while (true) {
            $this->rdy(1);

            $command = yield $this->readMessage();

            if (0 === $command) {
                break;
            }
        }

        $this->disconnect();
    }

    public function readMessage(): ?Message
    {
        $frame = $this->readFrame();

        if ($frame instanceof Message || null === $frame) {
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

    /**
     * Update RDY state (indicate you are ready to receive N messages).
     */
    public function rdy(int $count): void
    {
        if ($this->rdy === $count) {
            return;
        }

        $this->command('RDY', (string) $count);

        $this->rdy = $count;
    }

    /**
     * Finish a message (indicate successful processing).
     */
    public function fin(string $id): void
    {
        $this->command('FIN', $id);

        --$this->rdy;
    }

    /**
     * Re-queue a message (indicate failure to process)
     * The re-queued message is placed at the tail of the queue, equivalent to having just published it,
     * but for various implementation specific reasons that behavior should not be explicitly relied upon and may change in the future.
     * Similarly, a message that is in-flight and times out behaves identically to an explicit REQ.
     */
    public function req(string $id, int $timeout): void
    {
        $this->command('REQ', [$id, $timeout]);

        --$this->rdy;
    }

    /**
     * Reset the timeout for an in-flight message.
     */
    public function touch(string $id): void
    {
        $this->command('TOUCH', $id);
    }
}
