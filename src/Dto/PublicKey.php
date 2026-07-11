<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use DateTimeImmutable;
use Sigilbase\Internal\Json;
use stdClass;

/**
 * A published Ed25519 signing key. Retired keys stay published: checkpoints
 * signed while a key was active remain verifiable forever.
 */
final readonly class PublicKey
{
    public function __construct(
        public string $publicKey,
        public string $algorithm,
        public string $createdAt,
        public ?string $retiredAt,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $data): self
    {
        return new self(
            publicKey: Json::string($data, 'public_key'),
            algorithm: Json::string($data, 'algorithm'),
            createdAt: Json::string($data, 'created_at'),
            retiredAt: Json::nullableString($data, 'retired_at'),
        );
    }

    public function isRetired(): bool
    {
        return $this->retiredAt !== null;
    }

    public function createdAtDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->createdAt);
    }
}
