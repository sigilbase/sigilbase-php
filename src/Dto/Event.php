<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use DateTimeImmutable;
use Sigilbase\Internal\Json;
use stdClass;

/**
 * A ledger event as served by the API. Timestamps are kept as the raw
 * RFC 3339 strings from the wire because entry hashes commit to those exact
 * bytes; use the *Date() helpers when you want DateTimeImmutable.
 */
final readonly class Event
{
    public function __construct(
        public string $id,
        public int $sequence,
        public string $occurredAt,
        public string $receivedAt,
        public string $actor,
        public string $action,
        public ?string $resource,
        public stdClass $payload,
        public string $payloadHash,
        public string $prevHash,
        public string $entryHash,
        public ?string $checkpointId,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $data): self
    {
        return new self(
            id: Json::string($data, 'id'),
            sequence: Json::int($data, 'sequence'),
            occurredAt: Json::string($data, 'occurred_at'),
            receivedAt: Json::string($data, 'received_at'),
            actor: Json::string($data, 'actor'),
            action: Json::string($data, 'action'),
            resource: Json::nullableString($data, 'resource'),
            payload: Json::object($data, 'payload'),
            payloadHash: Json::string($data, 'payload_hash'),
            prevHash: Json::string($data, 'prev_hash'),
            entryHash: Json::string($data, 'entry_hash'),
            checkpointId: Json::nullableString($data, 'checkpoint_id'),
        );
    }

    public function occurredAtDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->occurredAt);
    }

    public function receivedAtDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->receivedAt);
    }
}
