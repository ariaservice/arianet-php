<?php

declare(strict_types=1);

namespace Arianet\Exception;

/**
 * HTTP 403: missing scope, IP not allowed, identity check needed...
 */
class PermissionDeniedException extends ApiException
{
}
