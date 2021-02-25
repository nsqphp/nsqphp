<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Nsq\Message;

final class MessageException extends NsqException
{
    public static function processed(Message $message): self
    {
        return new self(sprintf('Message "%s" already processed.', $message->id));
    }
}
