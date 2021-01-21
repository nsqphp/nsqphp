<?php

declare(strict_types=1);

use Nsq\Envelope;
use Nsq\Subscriber;
use Nsq\Writer;
use Nsq\Exception;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    public function test(): void
    {
        $writer = new Writer('tcp://localhost:4150');
        $writer->pub(__FUNCTION__, __FUNCTION__);

        $subscriber = new Subscriber('tcp://localhost:4150');
        $generator = $subscriber->subscribe(__FUNCTION__, __FUNCTION__, 1);

        /** @var null|Envelope $envelope */
        $envelope = $generator->current();

        static::assertInstanceOf(Envelope::class, $envelope);
        static::assertSame(__FUNCTION__, $envelope->message->body);
        $envelope->finish();

        $generator->next();
        static::assertNull($generator->current());

        $writer->mpub(__FUNCTION__, [
            'First mpub message.',
            'Second mpub message.',
        ]);

        $generator->next();
        /** @var null|Envelope $envelope */
        $envelope = $generator->current();
        static::assertInstanceOf(Envelope::class, $envelope);
        static::assertSame('First mpub message.', $envelope->message->body);
        $envelope->finish();

        $generator->next();
        /** @var null|Envelope $envelope */
        $envelope = $generator->current();
        static::assertInstanceOf(Envelope::class, $envelope);
        static::assertSame('Second mpub message.', $envelope->message->body);
        $envelope->requeue(0);

        $generator->next();
        /** @var null|Envelope $envelope */
        $envelope = $generator->current();
        static::assertInstanceOf(Envelope::class, $envelope);
        static::assertSame('Second mpub message.', $envelope->message->body);
        $envelope->finish();

        $writer->dpub(__FUNCTION__, 2000, 'Deferred message.');

        $generator->next();
        /** @var null|Envelope $envelope */
        $envelope = $generator->current();
        static::assertNull($envelope);

        $generator->send(Subscriber::CHANGE_TIMEOUT);
        $generator->send(10.0);

        /** @var null|Envelope $envelope */
        $envelope = $generator->current();
        static::assertInstanceOf(Envelope::class, $envelope);
        static::assertSame('Deferred message.', $envelope->message->body);
        $envelope->finish();

        static::assertFalse($subscriber->isClosed());
        $generator->send(Subscriber::STOP);
        static::assertTrue($subscriber->isClosed());
    }

    /**
     * @dataProvider pubFails
     */
    public function testPubFail(string $topic, string $body, string $exceptionMessage): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($exceptionMessage);

        $writer = new Writer('tcp://localhost:4150');
        $writer->pub($topic, $body);
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
