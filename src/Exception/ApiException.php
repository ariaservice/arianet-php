<?php

declare(strict_types=1);

namespace Arianet\Exception;

/**
 * The API answered with an error.
 *
 * Branch on getErrorCode() (the API's stable machine code, lower-cased; compare it
 * with the Arianet\ErrorCode constants), not on the message.
 */
class ApiException extends ArianetException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly ?string $reason = null,
        private readonly ?float $retryAfter = null,
        private readonly ?string $requestId = null,
        private readonly mixed $body = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** HTTP status. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** The API's stable machine code, lower-cased. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Narrows the code on some refusals (e.g. "identity_hold"). */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** Seconds the server asked to wait (Retry-After), if it did. */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /** Request identifier for support, when the server sent one. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** The decoded response body, or its text when it was not JSON. */
    public function getBody(): mixed
    {
        return $this->body;
    }
}
