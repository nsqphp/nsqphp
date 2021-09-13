<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Nsq\Config\ClientConfig;
use Nsq\Config\LookupConfig;
use Nsq\Exception\LookupException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\call;

final class Lookup
{
    private array $addresses;

    private array $subscriptions = [];

    private array $consumers = [];

    private LookupConfig $config;

    private LoggerInterface $logger;

    private ?string $watcherId = null;

    public function __construct(
        string | array $address,
        LookupConfig $config = null,
        LoggerInterface $logger = null,
    ) {
        $this->addresses = (array) $address;
        $this->config = $config ?? new LookupConfig();
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(): void
    {
        if (null !== $this->watcherId) {
            return;
        }

        $client = HttpClientBuilder::buildDefault();
        $logger = $this->logger;

        $requestHandler = static function (string $uri) use ($client, $logger): \Generator {
            /** @var Response $response */
            $response = yield $client->request(new Request($uri));

            $buffer = yield $response->getBody()->buffer();

            try {
                return Lookup\Response::fromJson($buffer);
            } catch (LookupException $e) {
                $logger->log($e->level(), $uri.' '.$e->getMessage());

                return null;
            }
        };

        $callback = function () use ($requestHandler): \Generator {
            foreach ($this->addresses as $address) {
                foreach ($this->subscriptions as $key => $subscription) {
                    [$topic, $channel] = \explode(':', $key);

                    $promise = call($requestHandler, $address.'/lookup?topic='.$topic);
                    $promise->onResolve(
                        function (?\Throwable $e, ?Lookup\Response $response) use (
                            $key,
                            $subscription,
                            $topic,
                            $channel
                        ) {
                            if (null !== $e) {
                                $this->logger->error($e->getMessage(), ['exception' => $e]);

                                return;
                            }

                            if (null === $response) {
                                return;
                            }

                            foreach ($response->producers as $producer) {
                                $address = sprintf('%s:%s', $producer->broadcastAddress, $producer->tcpPort);
                                $consumerKey = $key.$address;

                                if (\array_key_exists($consumerKey, $this->consumers)) {
                                    continue;
                                }

                                $this->logger->info('Consumer created.', \compact('address', 'topic', 'channel'));

                                yield ($this->consumers[$consumerKey] = new Consumer(
                                    $address,
                                    $topic,
                                    $channel,
                                    $subscription['callable'],
                                    $subscription['config'],
                                    $this->logger,
                                ))->connect();
                            }
                        },
                    );

                    yield $promise;
                }
            }
        };

        Loop::defer($callback);
        $this->watcherId = Loop::repeat($this->config->pollingInterval, $callback);
    }

    public function stop(): void
    {
        if (null === $this->watcherId) {
            return;
        }

        $this->logger->info('Lookup stopped, cancel watcher.');

        Loop::cancel($this->watcherId);
        $this->watcherId = null;

        foreach ($this->consumers as $key => $consumer) {
            $consumer->close();

            unset($this->consumers[$key]);
        }
    }

    public function subscribe(string $topic, string $channel, callable $onMessage, ClientConfig $config = null): void
    {
        $key = $topic.':'.$channel;

        if (\array_key_exists($key, $this->subscriptions)) {
            throw new \InvalidArgumentException('Subscription already exists.');
        }

        $this->subscriptions[$key] = [
            'callable' => $onMessage,
            'config' => $config,
        ];

        $this->logger->info('Subscribed', \compact('topic', 'channel'));
    }

    public function unsubscribe(string $topic, string $channel): void
    {
        $key = $topic.':'.$channel;

        unset($this->subscriptions[$key]);

        $this->logger->info('Unsubscribed', \compact('topic', 'channel'));
    }
}
