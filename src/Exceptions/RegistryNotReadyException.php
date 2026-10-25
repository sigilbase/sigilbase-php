<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * HTTP 409 `registry_not_ready`: the registry has no committed policy yet,
 * so it accepts nothing and records nothing.
 */
final class RegistryNotReadyException extends ApiException {}
