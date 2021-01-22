<?php

declare(strict_types=1);

use Nsq\Consumer;
use Nsq\Subscriber;
use PHPUnit\Framework\TestCase;

final class SubscriberTest extends TestCase
{
    private Subscriber $subscriber;

    protected function setUp(): void
    {
        $consumer = new Consumer(
            address: 'tcp://localhost:4150',
        );

        $this->subscriber = new Subscriber($consumer);
    }

    public function testChangeInterval(): void
    {
        $generator = $this->subscriber->subscribe(__FUNCTION__, __FUNCTION__, 0.1);

        self::assertSame(0.1, $generator->send(Subscriber::TIMEOUT));
        $generator->next();

        $generator->send(Subscriber::CHANGE_TIMEOUT);
        $generator->send(0.2);

        self::assertSame(0.2, $generator->send(Subscriber::TIMEOUT));
    }

    public function testInvalidChangeInterval(): void
    {
        $this->expectException(\Nsq\Exception::class);
        $this->expectExceptionMessage('Timeout must be float, "string" given.');

        $generator = $this->subscriber->subscribe(__FUNCTION__, __FUNCTION__);
        $generator->send(Subscriber::CHANGE_TIMEOUT);
        // @phpstan-ignore-next-line
        $generator->send('bla');
    }
}
