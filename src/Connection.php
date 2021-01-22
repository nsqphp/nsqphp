<?php

declare(strict_types=1);

namespace Nsq;

use Composer\InstalledVersions;
use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    public ?Socket $socket = null;

    protected LoggerInterface $logger;

    private bool $closed = false;

    private string $address;

    /**
     * @var array{client_id: string, hostname: string, user_agent: string}
     */
    private array $features;

    public function __construct(
        string $address,
        LoggerInterface $logger = null,
        string $clientId = null,
        string $hostname = null,
        string $userAgent = null
    ) {
        $this->address = $address;

        $this->features = [
            'client_id' => $clientId ?? '',
            'hostname' => $hostname ?? '',
            'user_agent' => $userAgent ?? 'nsqphp/'.InstalledVersions::getPrettyVersion('nsq/nsq'),
        ];

        $this->logger = $logger ?? new NullLogger();
    }

    public function connect(): void
    {
        $this->socket = (new Factory())->createClient($this->address);
        $this->send('  V2');

        $body = json_encode($this->features, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        $size = pack('N', \strlen($body));

        $this->logger->info('Feature Negotiation: '.http_build_query($this->features));

        $this->send('IDENTIFY '.PHP_EOL.$size.$body)->getResponse()->okOrFail();
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
        } catch (Throwable $e) {
            $this->closed = true;

            $this->logger->error($e->getMessage(), ['exception' => $e]);

            throw $e;
        }

        return $this;
    }

    public function receive(float $timeout = 0): ?Response
    {
        $socket = $this->socket();

        if (false === $socket->selectRead($timeout)) {
            return null;
        }

        $size = (new ByteBuffer($socket->read(Bytes::BYTES_SIZE)))->consumeUint32();

        return new Response(new ByteBuffer($socket->read($size)));
    }

    protected function getResponse(): Response
    {
        $response = $this->receive(0.1);

        if (null === $response) {
            throw new Exception('Response was expected, but null received.');
        }

        return $response;
    }

    private function socket(): Socket
    {
        if ($this->closed) {
            throw new Exception('This connection is closed, create new one.');
        }

        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket;
    }
}
