<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 404: the stream slug does not exist in your tenancy, or no event has the
 * requested sequence. Cross-tenant slugs are indistinguishable from missing
 * ones by design.
 */
final class NotFoundException extends ApiException {}
