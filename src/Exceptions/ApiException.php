<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * An error response from the Sigilbase API. Every subclass maps to one row
 * of the documented error catalogue; the raw decoded body is kept for
 * anything the typed surface does not expose.
 */
abstract class ApiException extends SigilbaseException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }
}
