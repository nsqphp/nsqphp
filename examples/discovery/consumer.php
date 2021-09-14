<?php

declare(strict_types=1);

require dirname(__DIR__).'/../vendor/autoload.php';

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nsq\Config\ClientConfig;
use Nsq\Config\LookupConfig;
use Nsq\Lookup;
use Nsq\Message;

Loop::run(static function () {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter());
    $logger = new Logger('consumer', [$handler], [new PsrLogMessageProcessor()]);

    $callable = static function (Message $message) {
        yield $message->finish();
    };

    $clientConfig = new ClientConfig();

    $lookupConfig = new LookupConfig();

    $watcherId = Loop::repeat(5000, function () {
        yield Amp\Dns\resolver()->reloadConfig();
    });

    $lookup = Lookup::create(
        ['http://nsqlookupd0:4161', 'http://nsqlookupd1:4161', 'http://nsqlookupd2:4161'],
        $lookupConfig,
        $logger,
    );

    $lookup->subscribe('local', 'local', $callable, $clientConfig);

    Loop::delay(10000, function () use ($lookup, $watcherId) {
        $lookup->stop();
        Loop::cancel($watcherId);
    });
});
