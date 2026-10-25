<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

use Sigilbase\Dto\Receipt;

/**
 * HTTP 409 `commitment_conflict` on an append: the stream rejects and
 * records key reuse, and this idempotency key already belongs to an event
 * with different content. Nothing was appended; the attempt is recorded in
 * the stream's decision log at `decisionSeq` (null for a batch, whose
 * conflicts each carry their own), and the signed receipts are here (and in
 * the client's receipt store).
 */
final class CommitmentConflictException extends ApiException
{
    /**
     * @param  array<string, mixed>  $body
     * @param  list<Receipt>  $receipts  every conflict's receipt: one for an append, one per conflicting item of a batch
     */
    public function __construct(
        string $message,
        array $body,
        public readonly ?int $decisionSeq,
        public readonly ?Receipt $receipt,
        public readonly array $receipts = [],
    ) {
        parent::__construct($message, 409, $body);
    }
}
