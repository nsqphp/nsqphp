<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Promise;
use Amp\Success;
use Nsq\Config\ClientConfig;
use Nsq\Exception\NsqException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\asyncCall;
use function Amp\call;

final class Producer extends Connection
{
    public function __construct(
        string $address,
        ClientConfig $clientConfig,
        LoggerInterface $logger,
    ) {
        parent::__construct(
            $address,
            $clientConfig,
            $logger,
        );

        $context = compact('address');
        $this->onConnect(function () use ($context): void {
            $this->logger->debug('Producer connected.', $context);
        });
        $this->onClose(function () use ($context): void {
            $this->logger->debug('Producer disconnected.', $context);
        });
        $this->logger->debug('Producer created.', $context);
    }

    public static function create(
        string $address,
        ClientConfig $clientConfig = null,
        LoggerInterface $logger = null,
    ): self {
        return new self(
            $address,
            $clientConfig ?? new ClientConfig(),
            $logger ?? new NullLogger(),
        );
    }

    public function connect(): Promise
    {
        if ($this->isConnected()) {
            return call(static function (): void {
            });
        }

        return call(function (): \Generator {
            yield parent::connect();

            $buffer = new Buffer();

            asyncCall(function () use ($buffer): \Generator {
                while (null !== $chunk = yield $this->read()) {
                    $buffer->append($chunk);

                    while ($frame = Parser::parse($buffer)) {
                        switch (true) {
                            case $frame instanceof Frame\Response:
                                if ($frame->isHeartBeat()) {
                                    yield $this->write(Command::nop());
                                }

                                // Ok received
                                break;
                            case $frame instanceof Frame\Error:
                                $this->handleError($frame);

                                break;
                            default:
                                throw new NsqException('Unreachable statement.');
                        }
                    }
                }

                $this->close(false);
            });
        });
    }

    /**
     * @param array<int, string>|string $body
     *
     * @psalm-param positive-int|0      $delay
     *
     * @psalm-return Promise<bool>
     */
    public function publish(string $topic, string | array $body, int $delay = null): Promise
    {
        if (!$this->isConnected()) {
            return new Success(false);
        }

        return call(
            function (iterable $commands): \Generator {
                try {
                    foreach ($commands as $command) {
                        yield $this->write($command);
                    }

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            },
            (static function () use ($topic, $body, $delay): \Generator {
                if (\is_array($body) && null === $delay) {
                    yield Command::mpub($topic, $body);
                } elseif (null !== $delay) {
                    foreach ((array) $body as $content) {
                        yield Command::dpub($topic, $content, $delay);
                    }
                } else {
                    yield Command::pub($topic, $body);
                }
            })(),
        );
    }
}
