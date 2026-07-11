<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 422: the request failed validation. Field errors are keyed exactly as the
 * API returns them — `payload`, `occurred_at`, and for batch appends
 * `events.{index}.{field}`.
 */
final class ValidationException extends ApiException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly array $errors,
        array $body = [],
    ) {
        parent::__construct($message, 422, $body);
    }
}
