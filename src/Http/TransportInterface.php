<?php

declare(strict_types=1);

namespace Arianet\Http;

use Arianet\Exception\ConnectionException;

/**
 * Sends one HTTP request. Implementations must not follow redirects.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException when no answer was received
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
