<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 429: the per-key rate limit was exceeded. A 429 is a throttle, never a
 * drop — wait `retryAfter` seconds and the request will be accepted.
 * Idempotent requests are retried automatically before this is thrown.
 */
final class RateLimitedException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfter,
        array $body = [],
    ) {
        parent::__construct($message, 429, $body);
    }
}
