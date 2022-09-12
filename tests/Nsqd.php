<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class Nsqd
{
    public Process $process;

    public string $address;

    private static Filesystem $fs;

    public function __construct(
        public readonly string $dataPath,
        public readonly int $httpPort,
        public readonly int $tcpPort,
    ) {
        self::$fs ??= new Filesystem();
        self::$fs->mkdir($this->dataPath);

        $nsqd = new Process([
            './nsqd',
            sprintf('-data-path=%s', $this->dataPath),
            sprintf('-http-address=0.0.0.0:%s', $this->httpPort),
            sprintf('-tcp-address=0.0.0.0:%s', $this->tcpPort),
            '-log-level=debug',
        ], dirname(__DIR__).'/bin');

        $nsqd->start();

        while (false === @fsockopen('localhost', $this->tcpPort)) {
            if (!$nsqd->isRunning()) {
                throw new RuntimeException($nsqd->getErrorOutput());
            }

            usleep(10000);
        }

        $this->process = $nsqd;
        $this->address = sprintf('tcp://localhost:%s', $this->tcpPort);
    }

    public static function create(): self
    {
        do {
            $dir = sprintf('/tmp/%s', bin2hex(random_bytes(5)));
        } while (is_dir($dir));

        return new self(
            $dir,
            findFreePort(),
            findFreePort(),
        );
    }

    public function tail(string $topic, string $channel, int $messages): Process
    {
        $tail = new Process(
            [
                './nsq_tail',
                sprintf('-nsqd-tcp-address=localhost:%s', $this->tcpPort),
                sprintf('-topic=%s', $topic),
                sprintf('-channel=%s', $channel),
                sprintf('-n=%s', $messages),
                '-print-topic',
            ],
            dirname(__DIR__).'/bin',
            timeout: 10,
        );

        $tail->start();

        return $tail;
    }

    public function __destruct()
    {
        $this->process->stop();
        self::$fs->remove($this->dataPath);
    }
}

function findFreePort(): int
{
    $sock = socket_create_listen(0);
    assert($sock instanceof \Socket);

    socket_getsockname($sock, $addr, $port);
    socket_close($sock);

    return $port;
}
