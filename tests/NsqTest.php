<?php

declare(strict_types=1);

use Nsq\Message;
use Nsq\Consumer;
use Nsq\Subscriber;
use Nsq\Producer;
use Nsq\Exception;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    public function test(): void
    {
        $producer = new Producer('tcp://localhost:4150');
        $producer->pub(__FUNCTION__, __FUNCTION__);

        $consumer = new Consumer('tcp://localhost:4150');
        $subscriber = new Subscriber($consumer);
        $generator = $subscriber->subscribe(__FUNCTION__, __FUNCTION__, 1);

        /** @var null|Message $message */
        $message = $generator->current();

        self::assertInstanceOf(Message::class, $message);
        self::assertSame(__FUNCTION__, $message->body);
        $message->finish();

        $generator->next();
        self::assertNull($generator->current());

        $producer->mpub(__FUNCTION__, [
            'First mpub message.',
            'Second mpub message.',
        ]);

        $generator->next();
        /** @var null|Message $message */
        $message = $generator->current();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('First mpub message.', $message->body);
        $message->finish();

        $generator->next();
        /** @var null|Message $message */
        $message = $generator->current();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('Second mpub message.', $message->body);
        $message->requeue(0);

        $generator->next();
        /** @var null|Message $message */
        $message = $generator->current();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('Second mpub message.', $message->body);
        $message->finish();

        $producer->dpub(__FUNCTION__, 2000, 'Deferred message.');

        $generator->next();
        /** @var null|Message $message */
        $message = $generator->current();
        self::assertNull($message);

        $generator->send(Subscriber::CHANGE_TIMEOUT);
        $generator->send(10.0);

        /** @var null|Message $message */
        $message = $generator->current();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('Deferred message.', $message->body);
        $message->touch();
        $message->finish();

        self::assertFalse($consumer->isClosed());
        $generator->send(Subscriber::STOP);
        self::assertTrue($consumer->isClosed());
    }

    /**
     * @dataProvider pubFails
     */
    public function testPubFail(string $topic, string $body, string $exceptionMessage): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($exceptionMessage);

        $producer = new Producer('tcp://localhost:4150');
        $producer->pub($topic, $body);
    }

    /**
     * @return Generator<string, array>
     */
    public function pubFails(): Generator
    {
        yield 'Empty body' => ['test', '', 'E_BAD_MESSAGE PUB invalid message body size 0'];
        yield 'Invalid topic' => ['test$%^&', '', 'E_BAD_TOPIC PUB topic name "test$%^&" is not valid'];
    }
}
