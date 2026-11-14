<?php

declare(strict_types=1);

namespace Arianet\Exception;

/**
 * HTTP 401: the token is missing, expired or revoked.
 */
class AuthenticationException extends ApiException
{
}
