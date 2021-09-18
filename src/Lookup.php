<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Deferred;
use Amp\Dns\DnsException;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\NullCancellationToken;
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
     * @psalm-var array<string, array<string, Lookup\Producer>>
     */
    private array $producers = [];

    private array $consumers = [];

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
        return call(function (): \Generator {
            $requestHandler = function (string $uri): \Generator {
                /** @var Response $response */
                $response = yield $this->httpClient->request(new Request($uri.'/nodes'), new NullCancellationToken());

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
        $this->producers = [];
        $this->consumers = [];
        $this->running = [];
        $this->topicWatchers = [];

        $this->logger->info('Lookup stopped.');
    }

    /**
     * @psalm-suppress InvalidPropertyAssignmentValue
     */
    public function subscribe(string $topic, string $channel, callable $onMessage, ClientConfig $config = null): void
    {
        if (null !== ($this->running[$topic][$channel] ?? null)) {
            throw new \InvalidArgumentException('Subscription already exists.');
        }

        $this->running[$topic][$channel] = true;

        asyncCall(function () use ($topic, $channel, $onMessage, $config): \Generator {
            while (true) {
                if (null === ($this->running[$topic][$channel] ?? null)) {
                    return;
                }

                $producers = $this->producers[$topic] ??= new Deferred();

                if ($producers instanceof Deferred) {
                    /** @var array<string, Lookup\Producer> $producers */
                    $producers = yield $producers->promise();
                }

                foreach (array_diff_key($this->consumers, $producers) as $address => $producer) {
                    unset($this->consumers[$address]);
                }

                foreach ($producers as $address => $producer) {
                    if (null !== ($this->consumers[$address][$topic][$channel] ?? null)) {
                        continue;
                    }

                    $this->keepConnection(
                        Consumer::create(
                            $address,
                            $topic,
                            $channel,
                            $onMessage,
                            $config,
                            $this->logger,
                        ),
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

    private function keepConnection(Consumer $consumer): void
    {
        $this->consumers[$consumer->address][$consumer->topic][$consumer->channel] = $consumer;

        asyncCall(function () use ($consumer): \Generator {
            while (null !== ($this->consumers[$consumer->address][$consumer->topic][$consumer->channel] ?? null)) {
                try {
                    yield $consumer->connect();
                } catch (DnsException $e) {
                    $this->logger->error($e->getMessage(), ['exception' => $e]);

                    unset(
                        $this->consumers[$consumer->address],
                        $this->producers[$consumer->topic][$consumer->address],
                    );

                    return;
                } catch (\Throwable $e) {
                    $this->logger->error($e->getMessage(), ['exception' => $e]);

                    yield delay($this->config->pollingInterval);

                    continue;
                }

                while (true) {
                    if (null === ($this->consumers[$consumer->address][$consumer->topic][$consumer->channel] ?? null)) {
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

        asyncCall(function () use ($topic): \Generator {
            $cancellationToken = new NullCancellationToken();
            $requestHandler = function (string $uri) use ($topic, $cancellationToken): \Generator {
                $this->logger->debug('Lookup', compact('topic'));

                /** @var Response $response */
                $response = yield $this->httpClient->request(new Request($uri.'/lookup?topic='.$topic), $cancellationToken);

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
