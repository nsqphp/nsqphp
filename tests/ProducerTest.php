<?php

declare(strict_types=1);

use Amp\Loop;
use Amp\Process\Process;
use Nsq\Exception\ServerException;
use Nsq\Producer;
use PHPUnit\Framework\TestCase;
use function Amp\ByteStream\buffer;
use function Amp\Promise\wait;

final class ProducerTest extends TestCase
{
    /**
     * @param array<int, string>|string $body
     *
     * @dataProvider data
     */
    public function testPublish(array | string $body, string $expected): void
    {
        $process = new Process(
            sprintf('bin/nsq_tail -topic %s -channel default -nsqd-tcp-address localhost:4150 -n 1', __FUNCTION__),
        );
        wait($process->start());

        $producer = Producer::create('tcp://localhost:4150');
        wait($producer->connect());
        wait($producer->publish(__FUNCTION__, $body));

        wait($process->join());

        self::assertSame($expected, wait(buffer($process->getStdout())));
    }

    /**
     * @return Generator<int, array{0: string|array, 1: string}>
     */
    public function data(): Generator
    {
        yield ['Test Message One!', 'Test Message One!'.PHP_EOL];
    }

    /**
     * @dataProvider pubFails
     */
    public function testPubFail(string $topic, string $body, string $exceptionMessage): void
    {
        $this->expectException(ServerException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $producer = Producer::create('tcp://localhost:4150');

        Loop::run(static function () use ($producer, $topic, $body): Generator {
            yield $producer->connect();

            yield $producer->publish($topic, $body);
        });
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
