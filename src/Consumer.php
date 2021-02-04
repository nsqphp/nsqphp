<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use Nsq\Exception\NsqError;
use Nsq\Exception\NsqException;
use Nsq\Protocol\Error;
use Nsq\Protocol\Message;
use Nsq\Protocol\Response;
use function Amp\asyncCall;
use function Amp\call;

final class Consumer extends Connection
{
    private int $rdy = 0;

    /**
     * @return Promise<void>
     */
    public function listen(
        string $topic,
        string $channel,
        callable $onMessage,
    ): Promise {
        return call(function () use ($topic, $channel, $onMessage): \Generator {
            yield $this->command('SUB', [$topic, $channel]);
            yield $this->checkIsOK();

            asyncCall(function () use ($onMessage): \Generator {
                yield $this->rdy(2500);

                while ($message = yield $this->readMessage()) {
                    $command = yield $onMessage($message);

                    if (true === $command) {
                        break;
                    }

                    if ($this->rdy < 1000) {
                        yield $this->rdy(2500);
                    }
                }

                return new Success();
            });
        });
    }

    /**
     * @return Promise<Message>
     */
    public function readMessage(): Promise
    {
        return call(function (): \Generator {
            $frame = yield $this->readFrame();

            if ($frame instanceof Message) {
                return $frame;
            }

            if ($frame instanceof Error) {
                if ($frame->type->terminateConnection) {
                    yield $this->close();
                }

                throw new NsqError($frame);
            }

            throw new NsqException('Unreachable statement.');
        });
    }

    /**
     * Update RDY state (indicate you are ready to receive N messages).
     *
     * @return Promise<void>
     */
    public function rdy(int $count): Promise
    {
        if ($this->rdy === $count) {
            return call(static function (): void {});
        }

        $this->rdy = $count;

        return $this->command('RDY', (string) $count);
    }

    /**
     * Finish a message (indicate successful processing).
     *
     * @return Promise<void>
     *
     * @internal
     */
    public function fin(string $id): Promise
    {
        $promise = $this->command('FIN', $id);
        $promise->onResolve(function (): void {
            --$this->rdy;
        });

        return $promise;
    }

    /**
     * Re-queue a message (indicate failure to process) The re-queued message is placed at the tail of the queue,
     * equivalent to having just published it, but for various implementation specific reasons that behavior should not
     * be explicitly relied upon and may change in the future. Similarly, a message that is in-flight and times out
     * behaves identically to an explicit REQ.
     *
     * @return Promise<void>
     *
     * @internal
     */
    public function req(string $id, int $timeout): Promise
    {
        $promise = $this->command('REQ', [$id, $timeout]);
        $promise->onResolve(function (): void {
            --$this->rdy;
        });

        return $promise;
    }

    /**
     * Reset the timeout for an in-flight message.
     *
     * @return Promise<void>
     *
     * @internal
     */
    public function touch(string $id): Promise
    {
        return $this->command('TOUCH', $id);
    }
}
