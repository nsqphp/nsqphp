<?php

declare(strict_types=1);

namespace Nsq\Exception;

use Nsq\Frame\Response;

final class ConsumerException extends NsqException
{
    public static function response(Response $response): self
    {
        return new self(sprintf('Consumer receive response "%s" from nsq, which not expected. ', $response->data));
    }
}
