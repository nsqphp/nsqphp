<?php

declare(strict_types=1);

namespace Nsq;

/**
 * @psalm-immutable
 */
final class Envelope
{
    public Message $message;

    /**
     * @var callable
     */
    private $acknowledge;

    /**
     * @var callable
     */
    private $requeue;

    /**
     * @var callable
     */
    private $touching;

    public function __construct(Message $message, callable $ack, callable $req, callable $touch)
    {
        $this->message = $message;
        $this->acknowledge = $ack;
        $this->requeue = $req;
        $this->touching = $touch;
    }

    public function ack(): void
    {
        \call_user_func($this->acknowledge);
    }

    public function retry(int $timeout): void
    {
        \call_user_func($this->requeue, $timeout);
    }

    public function touch(): void
    {
        \call_user_func($this->touching);
    }
}
