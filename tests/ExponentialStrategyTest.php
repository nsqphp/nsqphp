<?php

declare(strict_types=1);

use Nsq\Exception\ConnectionFail;
use Nsq\Reconnect\ExponentialStrategy;
use Nsq\Reconnect\TimeProvider;
use PHPUnit\Framework\TestCase;

final class ExponentialStrategyTest extends TestCase
{
    public function testTimeNotYetCome(): void
    {
        $timeProvider = new FakeTimeProvider();
        $strategy = new ExponentialStrategy(
            minDelay: 8,
            maxDelay: 32,
            timeProvider: $timeProvider,
        );

        $successConnect = static function (int $time = null) use ($strategy, $timeProvider): void {
            $timeProvider($time);

            $strategy->connect(static function (): void {
            });
        };
        $failConnect = static function (int $time = null) use ($strategy, $timeProvider): void {
            $timeProvider($time);

            try {
                $strategy->connect(function (): void {
                    throw new ConnectionFail('Time come but failed');
                });
            } catch (ConnectionFail $e) {
                self::assertSame('Time come but failed', $e->getMessage());

                return;
            }

            self::fail('Expecting exception with message "Time come but failed"');
        };
        $timeNotCome = static function (int $time = null) use ($strategy, $timeProvider): void {
            $timeProvider($time);

            try {
                $strategy->connect(function (): void {
                    throw new ConnectionFail('');
                });
            } catch (ConnectionFail $e) {
                self::assertSame('Time to reconnect has not yet come', $e->getMessage());

                return;
            }

            self::fail('Was expecting exception with message "Time to reconnect has not yet come"');
        };

        $failConnect(0);
        $timeNotCome(7);
        $failConnect(8);
        $timeNotCome(22);
        $timeNotCome(13);
        $failConnect(24);
        $successConnect(56);
        $failConnect();
        $timeNotCome();
        $timeNotCome(63);
        $failConnect(64);

        $this->expectException(ConnectionFail::class);
        $this->expectExceptionMessage('Time to reconnect has not yet come');

        $successConnect();
    }
}

class FakeTimeProvider implements TimeProvider
{
    public int $time = 0;

    public function time(): int
    {
        return $this->time;
    }

    public function __invoke(int $time = null): void
    {
        $this->time = $time ?? $this->time;
    }
}
