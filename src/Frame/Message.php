<?php

declare(strict_types=1);

namespace Nsq\Frame;

use Nsq\Frame;

final class Message extends Frame
{
    public function __construct(
        public int $timestamp,
        public int $attempts,
        public string $id,
        public string $body,
    ) {
        parent::__construct(self::TYPE_MESSAGE);
    }
}
