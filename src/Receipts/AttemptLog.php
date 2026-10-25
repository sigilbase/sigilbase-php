<?php

declare(strict_types=1);

namespace Sigilbase\Receipts;

/**
 * Every request a client sends, recorded before it is sent and again when
 * it is answered (or fails to be): its request digest, and what came back.
 * With the receipts, it lets a submitter show apart the attempts Sigilbase
 * decided (they have a receipt) from the ones that never got an answer,
 * which no receipt can cover: a request that never reached Sigilbase.
 */
interface AttemptLog
{
    /**
     * @param  array<string, mixed>  $attempt  id, stream, request_digest, keys, sent_at
     */
    public function started(array $attempt): void;

    /**
     * @param  array<string, mixed>  $outcome  status, outcome, decision_seq, receipt id, or error
     */
    public function finished(string $attemptId, array $outcome): void;
}
