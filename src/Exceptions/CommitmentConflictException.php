<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

use Sigilbase\Dto\Receipt;

/**
 * HTTP 409 `commitment_conflict` on an append: the stream rejects and
 * records key reuse, and this idempotency key already belongs to an event
 * with different content. Nothing was appended; the attempt is recorded in
 * the stream's decision log at `decisionSeq`, and the signed receipt for it
 * is here (and in the client's receipt store).
 */
final class CommitmentConflictException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        array $body,
        public readonly ?int $decisionSeq,
        public readonly ?Receipt $receipt,
    ) {
        parent::__construct($message, 409, $body);
    }
}
