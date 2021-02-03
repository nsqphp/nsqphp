<?php

declare(strict_types=1);

use Amp\Success;
use Nsq\Consumer;
use Nsq\Exception\MessageAlreadyFinished;
use Nsq\Protocol\Message;
use PHPUnit\Framework\TestCase;
use function Amp\Promise\wait;

final class MessageTest extends TestCase
{
    /**
     * @dataProvider messages
     */
    public function testDoubleFinish(Message $message): void
    {
        self::assertFalse($message->isFinished());

        wait($message->finish());

        self::assertTrue($message->isFinished());

        $this->expectException(MessageAlreadyFinished::class);
        $this->expectExceptionMessage('Can\'t finish message as it already finished.');

        wait($message->finish());
    }

    /**
     * @dataProvider messages
     */
    public function testDoubleRequeue(Message $message): void
    {
        self::assertFalse($message->isFinished());

        wait($message->requeue(1));

        self::assertTrue($message->isFinished());

        $this->expectException(MessageAlreadyFinished::class);
        $this->expectExceptionMessage('Can\'t requeue message as it already finished.');

        wait($message->requeue(5));
    }

    /**
     * @dataProvider messages
     */
    public function testTouchAfterFinish(Message $message): void
    {
        self::assertFalse($message->isFinished());

        wait($message->finish());

        $this->expectException(MessageAlreadyFinished::class);
        $this->expectExceptionMessage('Can\'t touch message as it already finished.');

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

        yield [new Message(0, 0, 'id', 'body', $consumer)];
    }
}
