<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 401: the bearer token is missing, malformed, unknown, or revoked.
 */
final class AuthenticationException extends ApiException {}
