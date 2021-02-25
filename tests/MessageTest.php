<?php

declare(strict_types=1);

use Amp\Loop;
use Amp\Success;
use Nsq\ConsumerInterface;
use Nsq\Exception\MessageException;
use Nsq\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    /**
     * @dataProvider messages
     */
    public function testDoubleFinish(Message $message): void
    {
        $this->expectException(MessageException::class);

        Loop::run(function () use ($message): Generator {
            yield $message->finish();
            yield $message->finish();
        });
    }

    /**
     * @dataProvider messages
     */
    public function testDoubleRequeue(Message $message): void
    {
        $this->expectException(MessageException::class);

        Loop::run(function () use ($message): Generator {
            yield $message->requeue(1);
            yield $message->requeue(5);
        });
    }

    /**
     * @dataProvider messages
     */
    public function testTouchAfterFinish(Message $message): void
    {
        $this->expectException(MessageException::class);

        Loop::run(function () use ($message): Generator {
            yield $message->finish();
            yield $message->touch();
        });
    }

    /**
     * @return Generator<int, array{0: Message}>
     */
    public function messages(): Generator
    {
        $consumer = $this->createMock(ConsumerInterface::class);
        $consumer->method('fin')->willReturn(new Success());
        $consumer->method('touch')->willReturn(new Success());
        $consumer->method('req')->willReturn(new Success());

        yield [new Message('id', 'body', 0, 0, $consumer)];
    }
}
