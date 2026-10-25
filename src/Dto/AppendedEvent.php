<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use DateTimeImmutable;
use Sigilbase\Internal\Json;
use stdClass;

/**
 * The confirmation returned by an append: the durable identity of the event
 * inside the ledger. `replayed` is true when the idempotency key matched an
 * existing event and nothing new was written (HTTP 200 rather than 201).
 * `receipt` is Sigilbase's signed receipt for the request; null with
 * `receiptPending` while its signature is owed, signed moments later.
 */
final readonly class AppendedEvent
{
    public function __construct(
        public string $id,
        public int $sequence,
        public string $entryHash,
        public string $receivedAt,
        public bool $replayed,
        public ?Receipt $receipt = null,
        public bool $receiptPending = false,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $data, bool $replayed): self
    {
        return new self(
            id: Json::string($data, 'id'),
            sequence: Json::int($data, 'sequence'),
            entryHash: Json::string($data, 'entry_hash'),
            receivedAt: Json::string($data, 'received_at'),
            replayed: $replayed,
            receipt: Receipt::fromResponse($data),
            receiptPending: ($data->receipt_pending ?? false) === true,
        );
    }

    public function receivedAtDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->receivedAt);
    }
}
