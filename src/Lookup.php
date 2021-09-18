<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Deferred;
use Amp\Dns\DnsException;
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

    private array $topicWatchers = [];

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
     * @psalm-return Promise<Lookup\Producer[]>
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
            foreach (array_keys($channels) as $channel) {
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
                    /** @var array<string, Lookup\Producer> $producers */
                    $producers = yield $producers->promise();
                }

                foreach (array_diff_key($consumers, $producers) as $address => $producer) {
                    unset($consumers[$address]);
                }

                foreach ($producers as $address => $producer) {
                    if (\array_key_exists($address, $consumers)) {
                        continue;
                    }

                    $this->keepConnection(
                        new Consumer(
                            $address,
                            $topic,
                            $channel,
                            $onMessage,
                            $config,
                            $this->logger,
                        ),
                        $consumers,
                    );
                }

                yield delay($this->config->pollingInterval);
            }
        });

        $this->watch($topic);

        $this->logger->info('Subscribed.', compact('topic', 'channel'));
    }

    public function unsubscribe(string $topic, string $channel): void
    {
        if (null === ($this->running[$topic][$channel] ?? null)) {
            $this->logger->debug('Not subscribed.', compact('topic', 'channel'));

            return;
        }

        unset($this->running[$topic][$channel]);

        if ([] === $this->running[$topic]) {
            unset($this->running[$topic]);
        }

        $this->logger->info('Unsubscribed.', compact('topic', 'channel'));
    }

    private function keepConnection(Consumer $consumer, &$consumers): void
    {
        $consumers[$consumer->address] = $consumer;

        asyncCall(function () use ($consumer, &$consumers) {
            while (\array_key_exists($consumer->address, $consumers)) {
                try {
                    yield $consumer->connect();
                } catch (DnsException $e) {
                    $this->logger->error($e->getMessage(), ['exception' => $e]);

                    unset($consumers[$consumer->address], $this->producers[$consumer->topic][$consumer->address]);

                    return;
                } catch (\Throwable $e) {
                    $this->logger->error($e->getMessage(), ['exception' => $e]);

                    yield delay($this->config->pollingInterval);

                    continue;
                }

                while (true) {
                    if (!\array_key_exists($consumer->address, $consumers)) {
                        $consumer->close();

                        return;
                    }

                    if (!$consumer->isConnected()) {
                        break;
                    }

                    yield delay(500);
                }
            }
        });
    }

    private function watch(string $topic): void
    {
        if (\array_key_exists($topic, $this->topicWatchers)) {
            return;
        }

        $this->topicWatchers[$topic] = true;

        asyncCall(function () use ($topic) {
            $requestHandler = function (string $uri) use ($topic): \Generator {
                $this->logger->debug('Lookup', compact('topic'));

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

                $producers = [];
                foreach ($responses as $response) {
                    foreach ($response->producers as $producer) {
                        $producers[$producer->toTcpUri()] = $producer;
                    }
                }

                if (($deferred = ($this->producers[$topic] ?? null)) instanceof Deferred) {
                    $deferred->resolve($producers);
                }
                $this->producers[$topic] = $producers;

                yield delay($this->config->pollingInterval);
            }

            unset($this->topicWatchers[$topic]);
        });
    }
}
