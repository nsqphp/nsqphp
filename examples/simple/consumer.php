<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nsq\Config\ClientConfig;
use Nsq\Consumer;
use Nsq\Message;

use function Amp\call;

Loop::run(static function () {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter());
    $logger = new Logger('publisher', [$handler], [new PsrLogMessageProcessor()]);

    $consumer = new Consumer(
        'tcp://localhost:4150',
        topic: 'local',
        channel: 'local',
        onMessage: static function (Message $message) use ($logger): Promise {
            return call(function () use ($message, $logger): Generator {
                $logger->info('Received: {body}', ['body' => $message->body]);

                yield $message->finish();
            });
        },
        clientConfig: new ClientConfig(
            deflate: false,
            snappy: true,
        ),
        logger: $logger,
    );

    yield $consumer->connect();
});
