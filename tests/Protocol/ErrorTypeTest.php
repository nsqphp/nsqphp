<?php

declare(strict_types=1);

namespace Protocol;

use Generator;
use Nsq\Protocol\ErrorType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ErrorTypeTest extends TestCase
{
    /**
     * @dataProvider data
     */
    public function testConstructor(string $type, bool $isConnectionTerminated): void
    {
        $errorType = new ErrorType($type);
        self::assertSame($isConnectionTerminated, $errorType->terminateConnection);
    }

    /**
     * @return Generator<string, array<int, bool|string>>
     */
    public function data(): Generator
    {
        foreach ((new ReflectionClass(ErrorType::class))->getConstants() as $constant => $isTerminated) {
            yield $constant => [$constant, $isTerminated];
        }
    }
}
