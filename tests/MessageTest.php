<?php

declare(strict_types=1);

use Amp\Success;
use Nsq\Consumer;
use Nsq\Exception\MessageException;
use Nsq\Message;
use PHPUnit\Framework\TestCase;
use function Amp\Promise\wait;

final class MessageTest extends TestCase
{
    /**
     * @dataProvider messages
     */
    public function testDoubleFinish(Message $message): void
    {
        wait($message->finish());

        $this->expectException(MessageException::class);

        wait($message->finish());
    }

    /**
     * @dataProvider messages
     */
    public function testDoubleRequeue(Message $message): void
    {
        wait($message->requeue(1));

        $this->expectException(MessageException::class);

        wait($message->requeue(5));
    }

    /**
     * @dataProvider messages
     */
    public function testTouchAfterFinish(Message $message): void
    {
        wait($message->finish());

        $this->expectException(MessageException::class);

        wait($message->touch());
    }

    /**
     * @return Generator<int, array{0: Message}>
     */
    public function messages(): Generator
    {
        $consumer = $this->createMock(Consumer::class);
        $consumer->method('fin')->willReturn(new Success());
        $consumer->method('touch')->willReturn(new Success());
        $consumer->method('req')->willReturn(new Success());

        yield [new Message('id', 'body', 0, 0, $consumer)];
    }
}
