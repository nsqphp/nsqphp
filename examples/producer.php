<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nsq\Config\ClientConfig;
use Nsq\Producer;

Loop::run(static function () {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter());
    $logger = new Logger('publisher', [$handler], [new PsrLogMessageProcessor()]);

    $producer = new Producer(
        'tcp://localhost:4150',
        clientConfig: new ClientConfig(
            deflate: false,
            snappy: false,
        ),
        logger: $logger,
    );

    yield $producer->connect();

    yield $producer->pub(topic: 'topic', body: 'Message body!');
});
