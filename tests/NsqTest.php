<?php

declare(strict_types=1);

use Nsq\Config;
use Nsq\Connection;
use Nsq\Envelope;
use Nsq\Reader;
use Nsq\Subscriber;
use Nsq\Writer;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    public function test(): void
    {
        $config = new Config('tcp://localhost:4150');

        $writer = new Writer(Connection::connect($config));
        $writer->pub(__FUNCTION__, __FUNCTION__);

        $subscriber = new Subscriber(new Reader(Connection::connect($config)));
        $generator = $subscriber->subscribe(__FUNCTION__, __FUNCTION__, 1);

        $envelope = $generator->current();

        static::assertInstanceOf(Envelope::class, $envelope);
        /** @var Envelope $envelope */
        static::assertSame(__FUNCTION__, $envelope->message->body);
        $envelope->ack();

        $generator->next();
        static::assertNull($generator->current());
    }
}
