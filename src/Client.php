<?php

declare(strict_types=1);

namespace Arianet;

use Arianet\Exception\ApiException;
use Arianet\Exception\ArianetException;
use Arianet\Exception\AuthenticationException;
use Arianet\Exception\BadRequestException;
use Arianet\Exception\ConflictException;
use Arianet\Exception\ConnectionException;
use Arianet\Exception\NotFoundException;
use Arianet\Exception\PaymentRequiredException;
use Arianet\Exception\PermissionDeniedException;
use Arianet\Exception\RateLimitException;
use Arianet\Exception\ServerException;
use Arianet\Exception\UnprocessableEntityException;
use Arianet\Http\CurlTransport;
use Arianet\Http\HttpResponse;
use Arianet\Http\TransportInterface;

/**
 * Client for the Arianet public API.
 *
 * Every API group is a property: $client->core, $client->cloud, $client->regionalVps,
 * $client->hosting, $client->baremetal and $client->sms.
 */
final class Client
{
    use Services;

    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.ariaservice.net';
    public const REGION_HEADER = 'Arianet-Region';

    private const STATUS_EXCEPTIONS = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        402 => PaymentRequiredException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => UnprocessableEntityException::class,
        429 => RateLimitException::class,
    ];

    private const DEFAULT_CODES = [401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 429 => 'rate_limited'];

    private readonly string $token;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly float $maxRetryWait;
    private readonly string $userAgent;
    private readonly TransportInterface $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param string|null $token personal API token; ARIANET_TOKEN is read when null.
     *     Create one at https://cloud.ariaservice.net/users/api-tokens.
     * @param array{
     *     base_url?: string,
     *     timeout?: float,
     *     max_retries?: int,
     *     max_retry_wait?: float,
     *     user_agent?: string,
     *     transport?: TransportInterface,
     *     sleep?: callable(float): void,
     * } $options
     */
    public function __construct(?string $token = null, array $options = [])
    {
        $this->token = $token ?? (string) (getenv('ARIANET_TOKEN') ?: '');
        $baseUrl = $options['base_url'] ?? (getenv('ARIANET_API_URL') ?: self::DEFAULT_BASE_URL);
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        self::checkBaseUrl($this->baseUrl);
        $this->timeout = (float) ($options['timeout'] ?? 60.0);
        $this->maxRetries = (int) ($options['max_retries'] ?? 3);
        $this->maxRetryWait = (float) ($options['max_retry_wait'] ?? 60.0);
        $this->userAgent = trim('arianet-php/' . self::VERSION . ' ' . ($options['user_agent'] ?? ''));
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
        $this->initServices();
    }

    /**
     * Call any API path (including its prefix, e.g. "/cloud/v1/servers").
     *
     * Generated methods use this too; call it for endpoints this version does not
     * have yet. Returns the decoded JSON body.
     *
     * @param array<string, mixed> $query
     * @param array{idempotency_key?: string, headers?: array<string, string>, region?: string} $options
     */
    public function request(string $method, string $path, array $query = [], mixed $body = null, array $options = []): mixed
    {
        return $this->send($method, $path, $query, $body, $options, false);
    }

    /**
     * @internal used by the generated services
     *
     * @param array<string, mixed> $query
     * @param array{idempotency_key?: string, headers?: array<string, string>, region?: string} $options
     */
    public function send(string $method, string $path, array $query, mixed $body, array $options, bool $idempotent): mixed
    {
        $method = strtoupper($method);
        $idempotencyKey = $options['idempotency_key'] ?? null;
        if ($idempotent && $idempotencyKey === null && $method !== 'GET') {
            $idempotencyKey = self::uuid4();
        }

        $url = $this->baseUrl . $path;
        $queryString = self::buildQuery($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $headers = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = $this->userAgent;
        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        if (isset($options['region']) && $options['region'] !== '') {
            $headers[self::REGION_HEADER] = $options['region'];
        }

        // A network error or a 5xx is only repeated when that cannot do the work twice.
        $safe = $method === 'GET' || $method === 'HEAD' || $idempotencyKey !== null;
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->attempt($method, $url, $headers, $payload);
            } catch (ApiException|ConnectionException $e) {
                $wait = $this->retryWait($e, $attempt, $safe);
                if ($attempt >= $this->maxRetries || $wait === null) {
                    throw $e;
                }
                ($this->sleep)($wait);
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function attempt(string $method, string $url, array $headers, ?string $payload): mixed
    {
        $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
        $error = self::errorFromResponse($response);
        if ($error !== null) {
            throw $error;
        }
        if (trim($response->body) === '') {
            return null;
        }
        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $response->body;
        }
    }

    private function retryWait(ArianetException $e, int $attempt, bool $safe): ?float
    {
        if ($e instanceof ApiException) {
            $status = $e->getStatusCode();
            if ($status === 429 || ($safe && in_array($status, [502, 503, 504], true))) {
                $wait = $e->getRetryAfter() ?? self::backoff($attempt);

                return $wait <= $this->maxRetryWait ? $wait : null;
            }

            return null;
        }

        return $safe ? self::backoff($attempt) : null;
    }

    private static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * (2 ** $attempt));

        return $base + $base / 4 * (mt_rand() / mt_getrandmax());
    }

    /**
     * The exception a response stands for, or null. Reads the three error shapes:
     * {"success": false, "error": {"code", "message"}} (core API),
     * {"error": "message", "code", "reason"} (cloud API) and {"code", "message"} (product APIs).
     */
    private static function errorFromResponse(HttpResponse $response): ?ApiException
    {
        $decoded = true;
        try {
            $data = trim($response->body) === '' ? null : json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = $response->body;
            $decoded = false;
        }
        $obj = is_array($data) ? $data : [];
        $failed = $response->status >= 300 || (array_key_exists('success', $obj) && $obj['success'] === false);
        if (!$failed) {
            return null;
        }

        $code = is_string($obj['code'] ?? null) ? $obj['code'] : null;
        $message = is_string($obj['message'] ?? null) ? $obj['message'] : null;
        $reason = is_string($obj['reason'] ?? null) ? $obj['reason'] : null;
        $err = $obj['error'] ?? null;
        if (is_string($err)) {
            $message = $err;
        } elseif (is_array($err)) {
            $code = is_string($err['code'] ?? null) ? $err['code'] : $code;
            $message = is_string($err['message'] ?? null) ? $err['message'] : $message;
            $reason = is_string($err['reason'] ?? null) ? $err['reason'] : $reason;
        }
        if ($code === null || $code === '') {
            $code = $decoded ? (self::DEFAULT_CODES[$response->status] ?? 'request_failed') : 'unexpected_response';
        }
        if ($message === null || $message === '') {
            $message = sprintf('request failed with HTTP %d', $response->status);
        }

        $class = self::STATUS_EXCEPTIONS[$response->status] ?? ($response->status >= 500 ? ServerException::class : ApiException::class);

        return new $class(
            $response->status,
            strtolower($code),
            $message,
            $reason,
            self::parseRetryAfter($response),
            $response->headers['x-request-id'] ?? $response->headers['request-id'] ?? null,
            $data,
        );
    }

    /** Retry-After (seconds or an HTTP date), or RateLimit-Reset on a 429. */
    private static function parseRetryAfter(HttpResponse $response): ?float
    {
        $value = trim($response->headers['retry-after'] ?? '');
        if ($value !== '') {
            if (ctype_digit($value)) {
                return (float) $value;
            }
            $when = strtotime($value);
            if ($when !== false) {
                return (float) max(0, $when - time());
            }
        }
        if ($response->status === 429) {
            $reset = trim($response->headers['ratelimit-reset'] ?? '');
            if (ctype_digit($reset) && (int) $reset > 0) {
                return (float) $reset;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                $text = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode($text);
            }
        }

        return implode('&', $parts);
    }

    private static function checkBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $scheme = $parts['scheme'] ?? '';
        if ($host === '') {
            throw new ArianetException(sprintf('invalid base URL "%s"', $url));
        }
        if ($scheme === 'https') {
            return;
        }
        $loopback = in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme === 'http' && $loopback) {
            return;
        }
        throw new ArianetException(sprintf('base URL "%s" must use https', $url));
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
