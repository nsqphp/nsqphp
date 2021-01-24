<?php

declare(strict_types=1);

namespace Nsq\Exception;

use RuntimeException;

final class UnexpectedResponse extends RuntimeException implements NsqException
{
}
