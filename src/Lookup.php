<?php
declare(strict_types=1);

namespace Nsq;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Generator;
use InvalidArgumentException;
use Nsq\Config\ClientConfig;
use Nsq\Config\LookupConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\call;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function compact;
use function explode;
use function sprintf;
use const JSON_THROW_ON_ERROR;

final class Lookup
{
    private array $addresses;

    private array $subscriptions = [];

    private array $consumers = [];

    private LookupConfig $config;

    private LoggerInterface $logger;

    private bool $isRunning = false;

    public function __construct(
        string|array $address,
        LookupConfig $config = null,
        LoggerInterface $logger = null,
    ) {
        $this->addresses = (array) $address;
        $this->config = $config ?? new LookupConfig();
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->isRunning = true;

        $client = HttpClientBuilder::buildDefault();

        $requestHandler = static function (string $uri, string $topic) use ($client): \Generator {
            /** @var Response $response */
            $response = yield $client->request(new Request($uri.'/lookup?topic='.$topic));

            $buffer = yield $response->getBody()->buffer();

            $data = json_decode($buffer, true, flags: JSON_THROW_ON_ERROR);

            if ('TOPIC_NOT_FOUND' === ($data['message'] ?? null)) {
                return null;
            }

            return array_map(
                static function (array $data) {
                    return sprintf('%s:%s', $data['broadcast_address'], $data['tcp_port']);
                },
                $data['producers'],
            );
        };

        $callback = function () use ($requestHandler): Generator {
            $promises = [];
            foreach ($this->addresses as $address) {
                foreach (array_keys($this->subscriptions) as $subscription) {
                    [$topic] = explode(':', $subscription);

                    $promises[$topic] = call($requestHandler, $address, $topic);
                }
            }

            $bodies = yield $promises;

            $consumers = [];
            foreach ($this->subscriptions as $key => $subscription) {
                [$topic, $channel] = explode(':', $key);

                if (!array_key_exists($topic, $bodies)) {
                    continue;
                }

                $addresses = array_filter($bodies[$topic], 'strlen');
                if ([] === $addresses) {
                    continue;
                }

                foreach ($addresses as $address) {
                    $consumerKey = $key.$address;

                    if (array_key_exists($consumerKey, $this->consumers)) {
                        continue;
                    }

                    $this->consumers[$consumerKey] = $consumers[] = new Consumer(
                        $address,
                        $topic,
                        $channel,
                        $subscription['callable'],
                        $subscription['config'],
                        $this->logger,
                    );

                    $this->logger->info('Consumer {address}:{topic}:{channel} created.', compact('address', 'topic', 'channel'));
                }
            }

            yield array_map(static fn (Consumer $consumer) => $consumer->connect(), $consumers);
        };

        asyncCall($callback);

        $watcherId = Loop::repeat(
            $this->config->pollingInterval,
            function () use (&$watcherId, $callback) {
                if (!$this->isRunning) {
                    $this->logger->info('Lookup stopped, cancel watcher.');

                    Loop::cancel($watcherId);

                    return;
                }

                yield call($callback);
            },
        );
    }

    public function stop(): void
    {
        $this->isRunning = false;
    }

    public function subscribe(string $topic, string $channel, callable $onMessage, ClientConfig $clientConfig): void
    {
        $key = $topic.':'.$channel;

        if (array_key_exists($key, $this->subscriptions)) {
            throw new InvalidArgumentException('Subscription already exists.');
        }

        $this->subscriptions[$key] = [
            'callable' => $onMessage,
            'config' => $clientConfig,
        ];
    }

    public function unsubscribe(string $topic, string $channel): void
    {
        $key = $topic.':'.$channel;

        unset($this->subscriptions[$key]);
    }
}
