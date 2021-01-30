<?php

declare(strict_types=1);

namespace Nsq;

use Nsq\Exception\NsqError;
use Nsq\Exception\NsqException;
use Nsq\Protocol\Error;
use Nsq\Protocol\Message;

final class Consumer extends Connection
{
    private int $rdy = 0;

    /**
     * Subscribe to a topic/channel.
     */
    public function sub(string $topic, string $channel): void
    {
        $this->command('SUB', [$topic, $channel])->checkIsOK();
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
