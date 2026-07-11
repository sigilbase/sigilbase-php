<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 403: the API key is valid but not allowed to do this — most commonly a
 * key that is not scoped to the requested stream, or a plan-gated endpoint.
 */
final class AccessDeniedException extends ApiException {}
