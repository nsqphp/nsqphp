<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Deferred;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Nsq\Config\ClientConfig;
use Nsq\Config\LookupConfig;
use Nsq\Exception\LookupException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\call;
use function Amp\delay;

final class Lookup
{
    /**
     * @var array<string, array<string, \Nsq\Lookup\Producer[]>>
     */
    private array $producers = [];

    private array $running = [];

    public function __construct(
        private array $addresses,
        private LookupConfig $config,
        private LoggerInterface $logger,
        private DelegateHttpClient $httpClient,
    ) {
    }

    public static function create(
        string | array $address,
        LookupConfig $config = null,
        LoggerInterface $logger = null,
        DelegateHttpClient $httpClient = null,
    ): self {
        return new self(
            (array) $address,
            $config ?? new LookupConfig(),
            $logger ?? new NullLogger(),
            $httpClient ?? HttpClientBuilder::buildDefault(),
        );
    }

    /**
     * @return Promise<Lookup\Producer>
     */
    public function nodes(): Promise
    {
        return call(function () {
            $requestHandler = function (string $uri): \Generator {
                /** @var Response $response */
                $response = yield $this->httpClient->request(new Request($uri.'/nodes'));

                try {
                    return Lookup\Response::fromJson(yield $response->getBody()->buffer());
                } catch (LookupException $e) {
                    $this->logger->log($e->level(), $uri.' '.$e->getMessage());

                    return null;
                }
            };

            $promises = [];
            foreach ($this->addresses as $address) {
                $promises[$address] = call($requestHandler, $address);
            }

            $nodes = [];
            /** @var Lookup\Response $response */
            foreach (yield $promises as $response) {
                foreach ($response->producers as $producer) {
                    $nodes[$producer->toTcpUri()] = $producer;
                }
            }

            return array_values($nodes);
        });
    }

    public function stop(): void
    {
        foreach ($this->running as $topic => $channels) {
            foreach ($channels as $channel) {
                $this->unsubscribe($topic, $channel);
            }
        }

        $this->logger->info('Lookup stopped.');
    }

    public function subscribe(string $topic, string $channel, callable $onMessage, ClientConfig $config = null): void
    {
        if (null !== ($this->running[$topic][$channel] ?? null)) {
            throw new \InvalidArgumentException('Subscription already exists.');
        }

        $this->running[$topic][$channel] = true;

        /** @var Consumer[] $consumers */
        $consumers = [];

        asyncCall(function () use ($topic, $channel, $onMessage, $config, &$consumers) {
            while (true) {
                if (null === ($this->running[$topic][$channel] ?? null)) {
                    foreach ($consumers as $consumer) {
                        $consumer->close();
                    }

                    return;
                }

                $producers = $this->producers[$topic] ??= new Deferred();

                if ($producers instanceof Deferred) {
                    $producers = yield $producers->promise();
                }

                /** @var \Nsq\Lookup\Producer $producer */
                foreach ($producers as $producer) {
                    $address = $producer->toTcpUri();
                    $consumerKey = $topic.$address;

                    if (\array_key_exists($consumerKey, $consumers)) {
                        continue;
                    }

                    $promise = ($consumers[$consumerKey] = new Consumer(
                        $address,
                        $topic,
                        $channel,
                        $onMessage,
                        $config,
                        $this->logger,
                    ))->onClose(function () use ($consumerKey, &$consumers) {
                        unset($consumers[$consumerKey]);
                    })->connect();

                    $this->logger->debug('Consumer created.', compact('address', 'topic', 'channel'));

                    $promise->onResolve(function (?\Throwable $e) use ($consumerKey, &$consumers) {
                        if (null !== $e) {
                            $this->logger->error($e->getMessage());

                            unset($consumers[$consumerKey]);
                        }
                    });

                    yield $promise;
                }

                yield delay($this->config->pollingInterval);
            }
        });

        $this->watch($topic);

        $this->logger->info('Subscribed', compact('topic', 'channel'));
    }

    public function unsubscribe(string $topic, string $channel): void
    {
        if (null === ($this->running[$topic][$channel] ?? null)) {
            $this->logger->debug('Trying unsubscribe from non subscribed', compact('topic', 'channel'));

            return;
        }

        unset($this->running[$topic][$channel]);

        $this->logger->info('Unsubscribed', compact('topic', 'channel'));
    }

    private function watch(string $topic): void
    {
        asyncCall(function () use ($topic) {
            $requestHandler = function (string $uri) use ($topic): \Generator {
                /** @var Response $response */
                $response = yield $this->httpClient->request(new Request($uri.'/lookup?topic='.$topic));

                try {
                    return Lookup\Response::fromJson(yield $response->getBody()->buffer());
                } catch (LookupException $e) {
                    $this->logger->log($e->level(), $uri.' '.$e->getMessage());

                    return null;
                }
            };

            while (\array_key_exists($topic, $this->running)) {
                $promises = [];
                foreach ($this->addresses as $address) {
                    $promises[$address] = call($requestHandler, $address);
                }

                /** @var Lookup\Response[] $responses */
                $responses = yield $promises;

                foreach ($responses as $response) {
                    if (($deferred = ($this->producers[$topic] ?? null)) instanceof Deferred) {
                        $deferred->resolve($response->producers);
                        unset($this->producers[$topic]);
                    }

                    foreach ($response->producers as $producer) {
                        $this->producers[$topic][$producer->toTcpUri()] = $producer;
                    }
                }

                yield delay($this->config->pollingInterval);
            }
        });
    }
}
