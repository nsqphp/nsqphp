<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Nsq\Message;
use RuntimeException;

final class MessageAlreadyFinished extends RuntimeException implements NsqException
{
    public static function finish(Message $message): self
    {
        return new self('Can\'t finish message as it already finished.');
    }

    public static function requeue(Message $message): self
    {
        return new self('Can\'t requeue message as it already finished.');
    }

    public static function touch(Message $message): self
    {
        return new self('Can\'t touch message as it already finished.');
    }
}
