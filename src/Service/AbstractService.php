<?php

declare(strict_types=1);

namespace Arianet\Service;

use Arianet\Client;

/**
 * Base class of the generated API groups.
 */
abstract class AbstractService
{
    protected const PREFIX = '';

    public function __construct(
        private readonly Client $client,
        private readonly ?string $region = null,
    ) {
    }

    /**
     * A copy of this API group whose requests go to $region (the Arianet-Region header).
     */
    public function withRegion(?string $region): static
    {
        return new static($this->client, $region);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{idempotency_key?: string, headers?: array<string, string>, region?: string} $options
     */
    protected function send(string $method, string $path, array $query, mixed $body, array $options, bool $idempotent): mixed
    {
        if ($this->region !== null && !isset($options['region'])) {
            $options['region'] = $this->region;
        }

        return $this->client->send($method, $path, $query, $body, $options, $idempotent);
    }

    protected static function segment(int|string $value): string
    {
        return rawurlencode((string) $value);
    }
}
