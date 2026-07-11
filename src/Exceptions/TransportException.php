<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The HTTP request never produced a response: DNS failure, timeout,
 * connection refused. Wraps the PSR-18 client exception. Idempotent
 * requests are retried automatically before this is thrown.
 */
final class TransportException extends SigilbaseException
{
    public function __construct(string $message, ClientExceptionInterface $previous)
    {
        parent::__construct($message, 0, $previous);
    }
}
