<?php

declare(strict_types=1);

namespace Nsq;

use Generator;
use Nsq\Config\ClientConfig;
use Nsq\Exception\NsqError;
use Nsq\Exception\NsqException;
use Nsq\Protocol\Error;
use Nsq\Protocol\Message;
use Nsq\Protocol\Response;
use Psr\Log\LoggerInterface;

final class Consumer extends Connection
{
    private int $rdy = 0;

    public function __construct(
        private string $topic,
        private string $channel,
        string $address,
        ClientConfig $clientConfig = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($address, $clientConfig, $logger);
    }

    /**
     * @psalm-return Generator<int, Message|float|null, int|null, void>
     */
    public function generator(): \Generator
    {
        $this->command('SUB', [$this->topic, $this->channel])->checkIsOK();

        while (true) {
            $this->rdy(1);

            $timeout = $this->clientConfig->readTimeout;

            do {
                $deadline = microtime(true) + $timeout;

                $message = $this->hasMessage($timeout) ? $this->readMessage() : null;

                $timeout = ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime;
            } while (0 < $timeout && null === $message);

            $command = yield $message;

            if (0 === $command) {
                break;
            }
        }

        $this->close();
    }

    public function readMessage(): ?Message
    {
        $frame = $this->readFrame();

        if ($frame instanceof Message) {
            return $frame;
        }

        if ($frame instanceof Response && $frame->isHeartBeat()) {
            $this->command('NOP');

            return null;
        }

        if ($frame instanceof Error) {
            if ($frame->type->terminateConnection) {
                $this->close();
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
     *
     * @internal
     */
    public function fin(string $id): void
    {
        $this->command('FIN', $id);

        --$this->rdy;
    }

    /**
     * Re-queue a message (indicate failure to process) The re-queued message is placed at the tail of the queue,
     * equivalent to having just published it, but for various implementation specific reasons that behavior should not
     * be explicitly relied upon and may change in the future. Similarly, a message that is in-flight and times out
     * behaves identically to an explicit REQ.
     *
     * @internal
     */
    public function req(string $id, int $timeout): void
    {
        $this->command('REQ', [$id, $timeout]);

        --$this->rdy;
    }

    /**
     * Reset the timeout for an in-flight message.
     *
     * @internal
     */
    public function touch(string $id): void
    {
        $this->command('TOUCH', $id);
    }
}
