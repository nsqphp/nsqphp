<?php

declare(strict_types=1);

namespace Nsq;

use Composer\InstalledVersions;
use Nsq\Exception\ConnectionFail;
use Nsq\Exception\UnexpectedResponse;
use Nsq\Reconnect\ExponentialStrategy;
use Nsq\Reconnect\ReconnectStrategy;
use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket\Raw\Exception;
use Socket\Raw\Factory;
use Socket\Raw\Socket;
use function addcslashes;
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

    private ReconnectStrategy $reconnect;

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
        ReconnectStrategy $reconnectStrategy = null,
        LoggerInterface $logger = null,
    ) {
        $this->address = $address;

        $this->features = [
            'client_id' => $clientId ?? '',
            'hostname' => $hostname ?? (static fn (mixed $h): string => \is_string($h) ? $h : '')(gethostname()),
            'user_agent' => $userAgent ?? 'nsqphp/'.InstalledVersions::getPrettyVersion('nsq/nsq'),
            'heartbeat_interval' => $heartbeatInterval,
            'sample_rate' => $sampleRate,
        ];

        $this->logger = $logger ?? new NullLogger();
        $this->reconnect = $reconnectStrategy ?? new ExponentialStrategy(logger: $this->logger);
    }

    public function connect(): void
    {
        $this->reconnect->connect(function (): void {
            try {
                $this->socket = (new Factory())->createClient($this->address);
            }
            // @codeCoverageIgnoreStart
            catch (Exception $e) {
                $this->logger->error('Connecting to {address} failed.', ['address' => $this->address]);

                throw ConnectionFail::fromThrowable($e);
            }
            // @codeCoverageIgnoreEnd

            $this->send('  V2');

            $body = json_encode($this->features, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
            $size = pack('N', \strlen($body));

            $this->send('IDENTIFY '.PHP_EOL.$size.$body)->response()->okOrFail();
        });
    }

    /**
     * Cleanly close your connection (no more messages are sent).
     */
    public function disconnect(): void
    {
        if (null === $this->socket) {
            return;
        }

        try {
            $this->socket->write('CLS'.PHP_EOL);
            $this->socket->close();
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->logger->debug($e->getMessage(), ['exception' => $e]);
        }
        // @codeCoverageIgnoreEnd

        $this->socket = null;
    }

    public function isReady(): bool
    {
        return null !== $this->socket;
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
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            $this->logger->error($e->getMessage(), ['exception' => $e]);

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd

        return $this;
    }

    public function hasMessage(float $timeout = 0): bool
    {
        try {
            return false !== $this->socket()->selectRead($timeout);
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd
    }

    public function receive(float $timeout = 0): ?Response
    {
        $socket = $this->socket();
        $deadline = microtime(true) + $timeout;

        if (!$this->hasMessage($timeout)) {
            return null;
        }

        try {
            $size = $socket->read(Bytes::BYTES_SIZE);

            if ('' === $size) {
                $this->disconnect();

                throw new ConnectionFail('Probably connection lost');
            }

            $buffer = new ByteBuffer(
                $socket->read(
                // @phpstan-ignore-next-line
                    unpack('N', $size)[1]
                )
            );

            $this->logger->debug('Received buffer: '.addcslashes($buffer->bytes(), PHP_EOL));

            $response = new Response($buffer);

            if ($response->isHeartBeat()) {
                $this->send('NOP'.PHP_EOL);

                return $this->receive(
                    ($currentTime = microtime(true)) > $deadline ? 0 : $deadline - $currentTime
                );
            }
        }
        // @codeCoverageIgnoreStart
        catch (Exception $e) {
            $this->disconnect();

            throw ConnectionFail::fromThrowable($e);
        }
        // @codeCoverageIgnoreEnd

        return $response;
    }

    protected function response(): Response
    {
        return $this->receive(1) ?? throw UnexpectedResponse::null();
    }

    private function socket(): Socket
    {
        if (null === $this->socket) {
            $this->connect();
        }

        return $this->socket ?? throw new ConnectionFail('This connection is closed, create new one.');
    }
}
