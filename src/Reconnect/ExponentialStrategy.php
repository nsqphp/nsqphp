<?php

declare(strict_types=1);

namespace Nsq\Reconnect;

use Nsq\Exception\ConnectionFail;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ExponentialStrategy implements ReconnectStrategy
{
    use LoggerAwareTrait;

    private int $delay;

    private int $nextTryAfter;

    private int $attempt = 0;

    private TimeProvider $timeProvider;

    public function __construct(
        private int $minDelay = 8,
        private int $maxDelay = 32,
        TimeProvider $timeProvider = null,
        LoggerInterface $logger = null,
    ) {
        $this->delay = 0;
        $this->timeProvider = $timeProvider ?? new RealTimeProvider();
        $this->nextTryAfter = $this->timeProvider->time();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritDoc}
     */
    public function connect(callable $callable): void
    {
        $currentTime = $this->timeProvider->time();

        if ($currentTime < $this->nextTryAfter) {
            throw new ConnectionFail('Time to reconnect has not yet come');
        }

        try {
            $callable();
        } catch (\Throwable $e) {
            $nextDelay = 0 === $this->delay ? $this->minDelay : $this->delay * 2;
            $this->delay = $nextDelay > $this->maxDelay ? $this->maxDelay : $nextDelay;
            $this->nextTryAfter = $currentTime + $this->delay;

            $this->logger->warning('Reconnect #{attempt} after {delay}s', ['attempt' => ++$this->attempt, 'delay' => $this->delay]);

            throw $e;
        }

        $this->delay = 0;
        $this->attempt = 0;
    }
}
