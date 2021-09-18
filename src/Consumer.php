<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Failure;
use Amp\Promise;
use Nsq\Config\ClientConfig;
use Nsq\Exception\ConsumerException;
use Nsq\Frame\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\call;

final class Consumer extends Connection
{
    private int $rdy = 0;

    /**
     * @var callable
     */
    private $onMessage;

    public function __construct(
        private string $address,
        private string $topic,
        private string $channel,
        callable $onMessage,
        private ClientConfig $clientConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(
            $this->address,
            $clientConfig,
            $this->logger,
        );

        $this->onMessage = $onMessage;

        $context = compact('address', 'topic', 'channel');
        $this->onConnect(function () use ($context) {
            $this->logger->debug('Consumer connected.', $context);
        });
        $this->onClose(function () use ($context) {
            $this->logger->debug('Consumer disconnected.', $context);
        });
        $this->logger->debug('Consumer created.', $context);
    }

    public static function create(
        string $address,
        string $topic,
        string $channel,
        callable $onMessage,
        ?ClientConfig $clientConfig = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            $address,
            $topic,
            $channel,
            $onMessage,
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
                yield $this->write(Command::sub($this->topic, $this->channel));

                if (null !== ($chunk = yield $this->read())) {
                    $buffer->append($chunk);
                }

                /** @var Response $response */
                $response = Parser::parse($buffer);

                if (!$response->isOk()) {
                    return new Failure(new ConsumerException('Fail subscription.'));
                }

                yield $this->rdy(1);

                /** @phpstan-ignore-next-line */
                asyncCall(function () use ($buffer): \Generator {
                    while (null !== $chunk = yield $this->read()) {
                        $buffer->append($chunk);

                        while ($frame = Parser::parse($buffer)) {
                            switch (true) {
                                case $frame instanceof Frame\Response:
                                    if ($frame->isHeartBeat()) {
                                        yield $this->write(Command::nop());

                                        break;
                                    }

                                    throw ConsumerException::response($frame);
                                case $frame instanceof Frame\Error:
                                    $this->handleError($frame);

                                    break;
                                case $frame instanceof Frame\Message:
                                    asyncCall($this->onMessage, Message::compose($frame, $this));

                                    break;
                            }

                            if ($this->rdy !== $this->clientConfig->rdyCount) {
                                yield $this->rdy($this->clientConfig->rdyCount);
                            }
                        }
                    }

                    $this->close(false);
                });
            });
        });
    }

    /**
     * Update RDY state (indicate you are ready to receive N messages).
     *
     * @return Promise<void>
     */
    public function rdy(int $count): Promise
    {
        if ($this->rdy === $count) {
            return call(static function (): void {
            });
        }

        $this->rdy = $count;

        return $this->write(Command::rdy($count));
    }

    /**
     * Finish a message (indicate successful processing).
     *
     * @return Promise<void>
     *
     * @internal
     */
    public function fin(string $id): Promise
    {
        return $this->write(Command::fin($id));
    }

    /**
     * Re-queue a message (indicate failure to process) The re-queued message is placed at the tail of the queue,
     * equivalent to having just published it, but for various implementation specific reasons that behavior should not
     * be explicitly relied upon and may change in the future. Similarly, a message that is in-flight and times out
     * behaves identically to an explicit REQ.
     *
     * @return Promise<void>
     *
     * @internal
     */
    public function req(string $id, int $timeout): Promise
    {
        return $this->write(Command::req($id, $timeout));
    }

    /**
     * Reset the timeout for an in-flight message.
     *
     * @return Promise<void>
     *
     * @internal
     */
    public function touch(string $id): Promise
    {
        return $this->write(Command::touch($id));
    }
}
