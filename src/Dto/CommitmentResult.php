<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use Sigilbase\Internal\Json;
use stdClass;

/**
 * A registry's decision about one submission (REGISTRY-SPEC, registry
 * outcomes): accepted, replay, conflict, invalid, refused_early,
 * refused_late, refused_scope, refused_paused, refused_archived or
 * throttled. Every one but a throttle beyond the hourly record cap is
 * recorded at `decisionSeq` and carries its signed receipt, so a refusal
 * is a result to keep, not an error to discard.
 */
final readonly class CommitmentResult
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $status,
        public string $outcome,
        public ?int $decisionSeq,
        public bool $recorded,
        public ?Receipt $receipt,
        public array $reasons,
        public ?int $sequence,
        public ?string $entryHash,
        public ?int $retryAfter,
        public bool $registry = true,
    ) {}

    /**
     * A commit sent to a stream that is not a registry: the key was
     * ignored and the event appended (or replayed) like any other.
     *
     * @internal
     */
    public static function fromStandardAppend(int $status, stdClass $body): self
    {
        $receipt = Receipt::fromResponse($body);

        return new self(
            status: $status,
            outcome: $receipt?->outcome() ?? ($status === 200 ? 'replay' : 'accepted'),
            decisionSeq: null,
            recorded: false,
            receipt: $receipt,
            reasons: [],
            sequence: Json::nullableInt($body, 'sequence'),
            entryHash: Json::nullableString($body, 'entry_hash'),
            retryAfter: null,
            registry: false,
        );
    }

    /**
     * @internal
     */
    public static function fromResponse(int $status, stdClass $body, ?int $retryAfter): self
    {
        $accepted = $body->accepted ?? null;

        return new self(
            status: $status,
            outcome: Json::string($body, 'outcome'),
            decisionSeq: Json::nullableInt($body, 'decision_seq'),
            recorded: ($body->recorded ?? false) === true,
            receipt: Receipt::fromResponse($body),
            reasons: array_values(array_filter((array) ($body->reasons ?? []), is_string(...))),
            sequence: is_int($body->sequence ?? null) ? $body->sequence : ($accepted instanceof stdClass && is_int($accepted->sequence ?? null) ? $accepted->sequence : null),
            entryHash: is_string($body->entry_hash ?? null) ? $body->entry_hash : ($accepted instanceof stdClass && is_string($accepted->entry_hash ?? null) ? $accepted->entry_hash : null),
            retryAfter: $retryAfter,
        );
    }

    public function accepted(): bool
    {
        return $this->outcome === 'accepted' || $this->outcome === 'replay';
    }
}
