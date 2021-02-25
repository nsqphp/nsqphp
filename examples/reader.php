<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nsq\Config\ClientConfig;
use Nsq\Reader;
use function Amp\Promise\wait;

$handler = new StreamHandler(ByteStream\getStdout());
$handler->setFormatter(new ConsoleFormatter());
$logger = new Logger('publisher', [$handler], [new PsrLogMessageProcessor()]);

$reader = new Reader(
    'tcp://localhost:4150',
    topic: 'local',
    channel: 'local',
    clientConfig: new ClientConfig(
        deflate: false,
        snappy: false,
    ),
    logger: $logger,
);

wait($reader->connect());

while (true) {
    $message = wait($reader->consume());

    $logger->info('Received: {body}', ['body' => $message->body]);

    wait($message->finish());
}
