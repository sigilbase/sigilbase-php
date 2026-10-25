<?php

declare(strict_types=1);

namespace Sigilbase\Http;

use stdClass;

/**
 * A decoded 2xx response plus the status code, which carries meaning on
 * appends (201 written, 200 idempotent replay), or an error status the
 * caller asked to read itself, with its Retry-After.
 *
 * @internal
 */
final readonly class ApiResponse
{
    public function __construct(
        public int $status,
        public stdClass $body,
        public ?int $retryAfter = null,
    ) {}
}
