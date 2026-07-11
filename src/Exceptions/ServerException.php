<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 5xx: Sigilbase failed to handle the request. Idempotent requests are
 * retried automatically before this is thrown.
 */
final class ServerException extends ApiException {}
