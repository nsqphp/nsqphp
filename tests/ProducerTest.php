<?php

declare(strict_types=1);

use Amp\Loop;
use Nsq\Exception\ServerException;
use Nsq\Producer;
use PHPUnit\Framework\TestCase;

final class ProducerTest extends TestCase
{
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
