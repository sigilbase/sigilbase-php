<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use Sigilbase\Internal\Json;
use stdClass;

/**
 * The signing keys published unauthenticated at GET /api/v1/keys. A
 * checkpoint signature only counts if its key is in this set — including
 * retired keys, which remain valid for checkpoints sealed while active.
 */
final readonly class PublicKeySet
{
    /**
     * @param  list<PublicKey>  $keys
     */
    public function __construct(
        public array $keys,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $response): self
    {
        return new self(array_map(
            PublicKey::fromObject(...),
            Json::objectList($response, 'keys'),
        ));
    }

    public function contains(string $publicKeyHex): bool
    {
        $needle = strtolower($publicKeyHex);

        foreach ($this->keys as $key) {
            if (hash_equals(strtolower($key->publicKey), $needle)) {
                return true;
            }
        }

        return false;
    }
}
