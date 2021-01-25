<?php

declare(strict_types=1);

use Nsq\Config\ClientConfig;
use Nsq\Consumer;
use Nsq\Message;
use Nsq\Producer;
use Nsq\Subscriber;
use Nyholm\NSA;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    public function test(): void
    {
        $producer = new Producer('tcp://localhost:4150');
        $producer->pub(__FUNCTION__, __FUNCTION__);

        $consumer = new Consumer(
            address: 'tcp://localhost:4150',
            clientConfig: new ClientConfig(
                heartbeatInterval: 1000,
                readTimeout: 1,
            ),
        );
        $subscriber = new Subscriber($consumer);
        $generator = $subscriber->subscribe(__FUNCTION__, __FUNCTION__);

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

        self::assertTrue($consumer->isReady());
        $generator->send(Subscriber::STOP);
        self::assertFalse($consumer->isReady());
    }
}
