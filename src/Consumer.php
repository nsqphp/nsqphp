<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Failure;
use Amp\Promise;
use Nsq\Config\ClientConfig;
use Nsq\Exception\ConsumerException;
use Nsq\Frame\Response;
use Nsq\Stream\NullStream;
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
        ClientConfig $clientConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(
            $this->address,
            $clientConfig,
            $this->logger,
        );

        $this->onMessage = $onMessage;
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
        if (!$this->stream instanceof NullStream) {
            return call(static function (): void {
            });
        }

        return call(function (): \Generator {
            yield parent::connect();

            $this->run();
        });
    }

    private function run(): void
    {
        $buffer = new Buffer();

        asyncCall(function () use ($buffer): \Generator {
            yield $this->stream->write(Command::sub($this->topic, $this->channel));

            if (null !== ($chunk = yield $this->stream->read())) {
                $buffer->append($chunk);
            }

            /** @var Response $response */
            $response = Parser::parse($buffer);

            if (!$response->isOk()) {
                return new Failure(new ConsumerException('Fail subscription.'));
            }

            yield $this->rdy(2500);

            /** @phpstan-ignore-next-line  */
            asyncCall(function () use ($buffer): \Generator {
                while (null !== $chunk = yield $this->stream->read()) {
                    $buffer->append($chunk);

                    while ($frame = Parser::parse($buffer)) {
                        switch (true) {
                            case $frame instanceof Frame\Response:
                                if ($frame->isHeartBeat()) {
                                    yield $this->stream->write(Command::nop());

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
                    }
                }

                $this->stream = new NullStream();
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

        return $this->stream->write(Command::rdy($count));
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
        --$this->rdy;

        return $this->stream->write(Command::fin($id));
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
        --$this->rdy;

        return $this->stream->write(Command::req($id, $timeout));
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
        return $this->stream->write(Command::touch($id));
    }
}
