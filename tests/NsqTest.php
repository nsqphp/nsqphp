<?php

declare(strict_types=1);

use Nsq\Config\ClientConfig;
use Nsq\Consumer;
use Nsq\Producer;
use Nsq\Protocol\Message;
use Nyholm\NSA;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    public function test(): void
    {
        $producer = new Producer('tcp://localhost:4150');
        $producer->pub(__FUNCTION__, __FUNCTION__);

        $consumer = new Consumer(
            topic: 'test',
            channel: 'test',
            address: 'tcp://localhost:4150',
            clientConfig: new ClientConfig(
                heartbeatInterval: 3000,
                readTimeout: 1,
            ),
        );
        $generator = $consumer->generator();

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

        $producer->dpub(__FUNCTION__, 'Deferred message.', 2000);

        $generator->next();
        /** @var null|Message $message */
        $message = $generator->current();
        self::assertNull($message);

        NSA::setProperty(
            NSA::getProperty($consumer, 'clientConfig'),
            'readTimeout',
            10,
        );

        $generator->next();

        /** @var null|Message $message */
        $message = $generator->current();
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('Deferred message.', $message->body);
        $message->touch();
        $message->finish();

        self::assertFalse($consumer->isClosed());
        $generator->send(0);
        self::assertTrue($consumer->isClosed());
    }
}
