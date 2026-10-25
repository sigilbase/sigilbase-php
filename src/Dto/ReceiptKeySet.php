<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use Sigilbase\Internal\Json;
use stdClass;

/**
 * The receipt keys published unauthenticated at GET /api/v1/receipt-keys,
 * retired ones included. A receipt names its key by key_id, the SHA-256 of
 * the raw public key, which {@see self::key()} recomputes rather than
 * trusting the id the response gives.
 */
final readonly class ReceiptKeySet
{
    /**
     * @param  list<array{key_id: string, public_key: string, created_at: string|null, retired_at: string|null}>  $keys
     */
    public function __construct(
        public array $keys,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $response): self
    {
        $keys = [];

        foreach (Json::objectList($response, 'keys') as $key) {
            $keys[] = [
                'key_id' => Json::string($key, 'key_id'),
                'public_key' => Json::string($key, 'public_key'),
                'created_at' => Json::nullableString($key, 'created_at'),
                'retired_at' => Json::nullableString($key, 'retired_at'),
            ];
        }

        return new self($keys);
    }

    /**
     * The key a receipt names, matched on the hash of its public key.
     *
     * @return array{key_id: string, public_key: string, created_at: string|null, retired_at: string|null}|null
     */
    public function key(string $keyId): ?array
    {
        foreach ($this->keys as $key) {
            $raw = @hex2bin($key['public_key']);

            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && hash_equals(hash('sha256', $raw), strtolower($keyId))) {
                return $key;
            }
        }

        return null;
    }
}
