<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 403 with error code `tenant_suspended`: the account is suspended and
 * ingestion is refused. Reads keep working.
 */
final class TenantSuspendedException extends ApiException {}
