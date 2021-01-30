<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Nsq\Protocol\Message;

final class MessageAlreadyFinished extends NsqException
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
