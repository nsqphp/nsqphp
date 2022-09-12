<?php

declare(strict_types=1);

use Amp\Loop;
use Nsq\Exception\ServerException;
use Nsq\Producer;
use PHPUnit\Framework\TestCase;

use function Amp\Promise\wait;

final class ProducerTest extends TestCase
{
    /**
     * @dataProvider bodies
     */
    public function testPublish(string $body): void
    {
        $nsqd = Nsqd::create();
        $tail = $nsqd->tail('test', 'test', 1);

        $producer = Producer::create($nsqd->address);

        wait($producer->connect());
        self::assertTrue(wait($producer->publish('test', $body)));

        self::assertSame(0, $tail->wait());

        self::assertSame("test | {$body}", trim($tail->getOutput()));
    }

    /**
     * @dataProvider bodies
     */
    public function testPublishMultiple(string $body): void
    {
        $nsqd = Nsqd::create();
        $tail = $nsqd->tail('test', 'test', 2);

        $producer = Producer::create($nsqd->address);

        wait($producer->connect());
        self::assertTrue(wait($producer->publish('test', [$body, $body])));

        self::assertSame(0, $tail->wait());

        self::assertSame("test | {$body}\ntest | {$body}", trim($tail->getOutput()));
    }

    /**
     * @dataProvider bodies
     */
    public function testPublishDeferred(string $body): void
    {
        $nsqd = Nsqd::create();
        $tail = $nsqd->tail('test', 'test', 1);

        $producer = Producer::create($nsqd->address);

        wait($producer->connect());
        self::assertTrue(wait($producer->publish('test', $body, 1)));

        self::assertSame(0, $tail->wait());

        self::assertSame("test | {$body}", trim($tail->getOutput()));
    }

    /**
     * @dataProvider pubFails
     */
    public function testPubFail(string $topic, string $body, string $exceptionMessage): void
    {
        $nsqd = Nsqd::create();

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $producer = Producer::create($nsqd->address);

        Loop::run(static function () use ($producer, $topic, $body): Generator {
            yield $producer->connect();

            yield $producer->publish($topic, $body);
        });
    }

    public function pubFails(): Generator
    {
        yield 'Empty body' => ['test', '', 'E_BAD_MESSAGE PUB invalid message body size 0'];
        yield 'Invalid topic' => ['test$%^&', '', 'E_BAD_TOPIC PUB topic name "test$%^&" is not valid'];
    }

    public function bodies(): Generator
    {
        yield 'Simple Body' => ['Simple Body'];
        yield 'Body with special chars' => ['test$%^&'];
    }
}
