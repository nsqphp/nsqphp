<?php

declare(strict_types=1);

use Nsq\Envelope;
use Nsq\Subscriber;
use Nsq\Writer;
use PHPUnit\Framework\TestCase;

final class NsqTest extends TestCase
{
    public function test(): void
    {
        $writer = new Writer('tcp://localhost:4150');
        $writer->pub(__FUNCTION__, __FUNCTION__);

        $subscriber = new Subscriber('tcp://localhost:4150');
        $generator = $subscriber->subscribe(__FUNCTION__, __FUNCTION__, 1);

        $envelope = $generator->current();

        static::assertInstanceOf(Envelope::class, $envelope);
        /** @var Envelope $envelope */
        static::assertSame(__FUNCTION__, $envelope->message->body);
        $envelope->finish();

        $generator->next();
        static::assertNull($generator->current());
    }
}
