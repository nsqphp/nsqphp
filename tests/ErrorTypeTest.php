<?php

declare(strict_types=1);

use Nsq\ErrorType;
use Nsq\Frame\Error;
use PHPUnit\Framework\TestCase;

final class ErrorTypeTest extends TestCase
{
    /**
     * @dataProvider data
     */
    public function testConstructor(Error $frame, bool $isConnectionTerminated): void
    {
        self::assertSame($isConnectionTerminated, ErrorType::terminable($frame));
    }

    /**
     * @return \Generator<int, array{0: Error, 1: bool}>
     */
    public function data(): Generator
    {
        yield [new Error('E_BAD_BODY'), true];
        yield [new Error('bla_bla'), true];
        yield [new Error('E_REQ_FAILED'), false];
    }
}
