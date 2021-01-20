<?php

declare(strict_types=1);

namespace Nsq;

class Reader extends Connection
{
    /**
     * Subscribe to a topic/channel.
     */
    public function sub(string $topic, string $channel): void
    {
        $buffer = sprintf('SUB %s %s', $topic, $channel).PHP_EOL;

        $this->write($buffer);
        $this->consume();
    }

    /**
     * Update RDY state (indicate you are ready to receive N messages).
     */
    public function rdy(int $count): void
    {
        $this->write('RDY '.$count.PHP_EOL);
    }

    /**
     * Finish a message (indicate successful processing).
     */
    public function fin(string $id): void
    {
        $this->write('FIN '.$id.PHP_EOL);
    }

    /**
     * Re-queue a message (indicate failure to process)
     * The re-queued message is placed at the tail of the queue, equivalent to having just published it,
     * but for various implementation specific reasons that behavior should not be explicitly relied upon and may change in the future.
     * Similarly, a message that is in-flight and times out behaves identically to an explicit REQ.
     */
    public function req(string $id, int $timeout): void
    {
        $this->write(sprintf('REQ %s %s', $id, $timeout).PHP_EOL);
    }

    /**
     * Reset the timeout for an in-flight message.
     */
    public function touch(string $id): void
    {
        $this->write('TOUCH '.$id.PHP_EOL);
    }
}
