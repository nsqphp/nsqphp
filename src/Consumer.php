<?php

declare(strict_types=1);

namespace Nsq;

class Consumer extends Connection
{
    private int $rdy = 0;

    /**
     * Subscribe to a topic/channel.
     */
    public function sub(string $topic, string $channel): void
    {
        $buffer = sprintf('SUB %s %s', $topic, $channel).PHP_EOL;

        $this->send($buffer)->getResponse()->okOrFail();
    }

    /**
     * Update RDY state (indicate you are ready to receive N messages).
     */
    public function rdy(int $count): void
    {
        if ($this->rdy === $count) {
            return;
        }

        $this->send('RDY '.$count.PHP_EOL);

        $this->rdy = $count;
    }

    public function consume(float $timeout): ?Message
    {
        $deadline = microtime(true) + $timeout;

        $response = $this->receive($timeout);
        if (null === $response) {
            return null;
        }

        if ($response->isHeartBeat()) {
            $this->nop();

            return $this->consume(
                ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime
            );
        }

        return $response->toMessage($this);
    }

    /**
     * Finish a message (indicate successful processing).
     */
    public function fin(string $id): void
    {
        $this->send('FIN '.$id.PHP_EOL);

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
        $this->send(sprintf('REQ %s %s', $id, $timeout).PHP_EOL);

        --$this->rdy;
    }

    /**
     * Reset the timeout for an in-flight message.
     */
    public function touch(string $id): void
    {
        $this->send('TOUCH '.$id.PHP_EOL);
    }

    public function nop(): void
    {
        $this->send('NOP'.PHP_EOL);
    }
}
