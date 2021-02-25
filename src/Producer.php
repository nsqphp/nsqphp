<?php

declare(strict_types=1);

namespace Nsq;

use Amp\Promise;
use Nsq\Config\ClientConfig;
use Nsq\Exception\NsqException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\call;

final class Producer extends Connection
{
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
        return call(function (): \Generator {
            yield parent::connect();

            $this->run();
        });
    }

    /**
     * @param array<int, string>|string $body
     *
     * @return Promise<void>
     */
    public function publish(string $topic, string | array $body): Promise
    {
        $command = \is_array($body)
            ? Command::mpub($topic, $body)
            : Command::pub($topic, $body);

        return $this->stream->write($command);
    }

    /**
     * @return Promise<void>
     */
    public function defer(string $topic, string $body, int $delay): Promise
    {
        return $this->stream->write(Command::dpub($topic, $body, $delay));
    }

    private function run(): void
    {
        $buffer = new Buffer();

        asyncCall(function () use ($buffer): \Generator {
            while (null !== $chunk = yield $this->stream->read()) {
                $buffer->append($chunk);

                while ($frame = Parser::parse($buffer)) {
                    switch (true) {
                        case $frame instanceof Frame\Response:
                            if ($frame->isHeartBeat()) {
                                yield $this->stream->write(Command::nop());
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
        });
    }
}
