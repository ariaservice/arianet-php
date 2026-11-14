<?php

declare(strict_types=1);

namespace Arianet\Exception;

/**
 * HTTP 429 that was still refused after the retries.
 */
class RateLimitException extends ApiException
{
}
