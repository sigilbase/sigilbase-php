<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use DateTimeImmutable;
use Sigilbase\Internal\Json;
use stdClass;

/**
 * A signed Merkle seal over a contiguous range of events. `createdAt` stays
 * a raw RFC 3339 string because the checkpoint hash commits to it verbatim.
 */
final readonly class Checkpoint
{
    public function __construct(
        public string $id,
        public int $sequenceFrom,
        public int $sequenceTo,
        public string $merkleRoot,
        public string $prevCheckpointHash,
        public string $checkpointHash,
        public string $signature,
        public string $publicKey,
        public string $createdAt,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $data): self
    {
        return new self(
            id: Json::string($data, 'id'),
            sequenceFrom: Json::int($data, 'sequence_from'),
            sequenceTo: Json::int($data, 'sequence_to'),
            merkleRoot: Json::string($data, 'merkle_root'),
            prevCheckpointHash: Json::string($data, 'prev_checkpoint_hash'),
            checkpointHash: Json::string($data, 'checkpoint_hash'),
            signature: Json::string($data, 'signature'),
            publicKey: Json::string($data, 'public_key'),
            createdAt: Json::string($data, 'created_at'),
        );
    }

    public function createdAtDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->createdAt);
    }
}
