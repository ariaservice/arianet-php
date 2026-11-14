<?php

declare(strict_types=1);

namespace Arianet\Exception;

/**
 * The request got no answer: DNS, TLS, timeout or a dropped connection.
 */
class ConnectionException extends ArianetException
{
}
