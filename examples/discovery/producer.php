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
use Nsq\Exception\NsqException;
use Nsq\Lookup;
use Nsq\Producer;
use function Amp\asyncCall;
use function Amp\delay;

Loop::run(static function () {
    $handler = new StreamHandler(ByteStream\getStdout());
    $handler->setFormatter(new ConsoleFormatter());
    $logger = new Logger('publisher', [$handler], [new PsrLogMessageProcessor()]);

    $clientConfig = new ClientConfig();

    /** @var Producer[] $producers */
    $producers = [];

    $lookupConfig = new LookupConfig();

    $lookup = Lookup::create(
        ['http://nsqlookupd0:4161', 'http://nsqlookupd1:4161', 'http://nsqlookupd2:4161'],
        $lookupConfig,
        $logger,
    );

    $isRunning = true;

    asyncCall(static function () use ($lookup, $clientConfig, $logger, &$producers, &$isRunning) {
        $stopList = [];

        while ($isRunning) {
            /** @var Lookup\Producer[] $nodes */
            $nodes = yield $lookup->nodes();

            foreach ($nodes as $node) {
                $toTcpUri = $node->toTcpUri();

                if (array_key_exists($toTcpUri, $producers) || array_key_exists($toTcpUri, $stopList)) {
                    continue;
                }

                $stopList[$node->broadcastAddress] = true;

                $logger->debug('Found new nsqd: '.$toTcpUri);

                asyncCall(function () use ($node, $clientConfig, $logger, &$producers, &$stopList) {
                    $producer = new Producer(
                        $node->toTcpUri(),
                        clientConfig: $clientConfig,
                        logger: $logger,
                    );

                    yield $producer->connect();

                    $producers[$node->toTcpUri()] = $producer;
                    unset($stopList[$node->toTcpUri()]);
                });
            }

            yield delay(5000);

            yield Amp\Dns\resolver()->reloadConfig();
        }
    });

    Loop::delay(5000, function () use (&$isRunning) {
        $isRunning = false;
    });

    $counter = 0;
    while (true) {
        if (!$isRunning) {
            foreach ($producers as $producer) {
                $producer->close();
            }

            break;
        }

        if ([] === $producers) {
            yield delay(200);

            continue;
        }

        $index = array_rand($producers);
        $producer = $producers[$index];

        try {
            yield $producer->publish('local', 'This is message of count '.$counter++);
        } catch (NsqException) {
            unset($producers[$index]);
        }
    }
});
