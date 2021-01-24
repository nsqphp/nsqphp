<?php

declare(strict_types=1);

namespace Nsq;

use Composer\InstalledVersions;
use Nsq\Exception\ConnectionFail;
use Nsq\Exception\UnexpectedResponse;
use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket\Raw\Exception;
use Socket\Raw\Factory;
use Socket\Raw\Socket;
use Throwable;
use function json_encode;
use function pack;
use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

/**
 * @internal
 */
abstract class Connection
{
    use LoggerAwareTrait;

    private string $address;

    private ?Socket $socket = null;

    private bool $closed = false;

    /**
     * @var array{
     *             client_id: string,
     *             hostname: string,
     *             user_agent: string,
     *             heartbeat_interval: int|null,
     *             }
     */
    private array $features;

    public function __construct(
        string $address,
        string $clientId = null,
        string $hostname = null,
        string $userAgent = null,
        int $heartbeatInterval = null,
        int $sampleRate = 0,
        LoggerInterface $logger = null,
    ) {
        $this->address = $address;

        $this->features = [
            'client_id' => $clientId ?? '',
            'hostname' => $hostname ?? (static fn (mixed $host): string => \is_string($host) ? $host : '')(gethostname()),
            'user_agent' => $userAgent ?? 'nsqphp/'.InstalledVersions::getPrettyVersion('nsq/nsq'),
            'heartbeat_interval' => $heartbeatInterval,
            'sample_rate' => $sampleRate,
        ];

        $this->logger = $logger ?? new NullLogger();
    }

    public function connect(): void
    {
        try {
            $this->socket = (new Factory())->createClient($this->address);
        } catch (Exception $e) {
            throw ConnectionFail::fromThrowable($e);
        }

        $this->send('  V2');

        $body = json_encode($this->features, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        $size = pack('N', \strlen($body));

        $this->logger->info('Feature Negotiation: '.http_build_query($this->features));

        $this->sendWithResponse('IDENTIFY '.PHP_EOL.$size.$body)->okOrFail();
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     */
    public function disconnect(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->send('CLS'.PHP_EOL);

            if (null !== $this->socket) {
                $this->socket->close();
            }
        } catch (Throwable $e) {
            $this->logger->debug($e->getMessage(), ['exception' => $e]);
        }

        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @psalm-suppress PossiblyFalseOperand
     */
    protected function auth(string $secret): string
    {
        $size = pack('N', \strlen($secret));

        return 'AUTH'.PHP_EOL.$size.$secret;
    }

    protected function send(string $buffer): self
    {
        $socket = $this->socket();

        $this->logger->debug('Send buffer: '.addcslashes($buffer, PHP_EOL));

        try {
            $socket->write($buffer);
        } catch (Exception $e) {
            $this->closed = true;

            $this->logger->error($e->getMessage(), ['exception' => $e]);

            throw ConnectionFail::fromThrowable($e);
        }

        return $this;
    }

    public function receive(float $timeout = 0): ?Response
    {
        $socket = $this->socket();
        $deadline = microtime(true) + $timeout;

        if (false === $socket->selectRead($timeout)) {
            return null;
        }

        $size = (new ByteBuffer($socket->read(Bytes::BYTES_SIZE)))->consumeUint32();
        $response = new Response(new ByteBuffer($socket->read($size)));

        if ($response->isHeartBeat()) {
            $this->send('NOP'.PHP_EOL);

            return $this->receive(
                ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime
            );
        }

        return $response;
    }

    protected function sendWithResponse(string $buffer): Response
    {
        $this->send($buffer);

        $response = $this->receive(0.1);

        if (null === $response) {
            throw new UnexpectedResponse('Response was expected, but null received.');
        }

        return $response;
    }

    private function socket(): Socket
    {
        if ($this->closed) {
            throw new ConnectionFail('This connection is closed, create new one.');
        }

        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket;
    }
}
