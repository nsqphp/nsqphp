<?php

declare(strict_types=1);

namespace Nsq;

use Generator;
use function get_debug_type;
use function microtime;
use function sprintf;

final class Subscriber extends Reader
{
    public const STOP = 0;
    public const CHANGE_TIMEOUT = 1;

    /**
     * @psalm-return Generator<int, Envelope|null, int|float|null, void>
     */
    public function subscribe(string $topic, string $channel, float $timeout = 0): Generator
    {
        $this->sub($topic, $channel);

        while (true) {
            $this->rdy(1);

            $message = $this->consume($timeout);

            $command = yield null === $message ? null : new Envelope($message, $this);

            if (self::STOP === $command) {
                break;
            }

            if (self::CHANGE_TIMEOUT === $command) {
                $newTimeout = yield null;

                if (!\is_float($newTimeout)) {
                    throw new Exception(sprintf('Timeout must be float, "%s" given.', get_debug_type($newTimeout)));
                }

                $timeout = $newTimeout;
            }
        }

        $this->disconnect();
    }

    private function consume(float $timeout): ?Message
    {
        $deadline = microtime(true) + $timeout;

        $buffer = $this->receive($timeout);
        if (null === $buffer) {
            return null;
        }

        $type = $buffer->consumeUint32();

        if (self::TYPE_RESPONSE === $type) {
            $response = $buffer->flush();

            if (self::HEARTBEAT === $response) {
                $this->send('NOP'.PHP_EOL);

                return $this->consume(
                    ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime
                );
            }

            throw new Exception(sprintf('Unexpected response: %s', $response));
        }

        if (self::TYPE_ERROR === $type) {
            throw new Exception(sprintf('NSQ return error: "%s"', $buffer->flush()));
        }

        if (self::TYPE_MESSAGE !== $type) {
            throw new Exception(sprintf('Expecting "%s" type, but NSQ return: "%s"', self::TYPE_MESSAGE, $type));
        }

        $timestamp = $buffer->consumeInt64();
        $attempts = $buffer->consumeUint16();
        $id = $buffer->consume(self::BYTES_ID);
        $body = $buffer->flush();

        return new Message($timestamp, $attempts, $id, $body);
    }
}
