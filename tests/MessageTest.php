<?php

declare(strict_types=1);

use Nsq\Consumer;
use Nsq\Exception;
use Nsq\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    /**
     * @dataProvider messages
     */
    public function testDoubleFinish(Message $message): void
    {
        self::assertFalse($message->isFinished());

        $message->finish();

        self::assertTrue($message->isFinished());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Can\'t finish message as it already finished.');

        $message->finish();
    }

    /**
     * @dataProvider messages
     */
    public function testDoubleRequeue(Message $message): void
    {
        self::assertFalse($message->isFinished());

        $message->requeue(1);

        self::assertTrue($message->isFinished());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Can\'t requeue message as it already finished.');

        $message->requeue(5);
    }

    /**
     * @dataProvider messages
     */
    public function testTouchAfterFinish(Message $message): void
    {
        self::assertFalse($message->isFinished());

        $message->finish();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Can\'t touch message as it already finished.');

        $message->touch();
    }

    /**
     * @return Generator<int, array{0: Message}>
     */
    public function messages(): Generator
    {
        yield [new Message(0, 0, 'id', 'body', $this->createStub(Consumer::class))];
    }
}
