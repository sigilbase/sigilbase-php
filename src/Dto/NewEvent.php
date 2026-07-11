<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sigilbase\Canonical\CanonicalJson;
use Sigilbase\Exceptions\InvalidPayloadException;
use Sigilbase\Internal\Rfc3339;
use stdClass;

/**
 * An event to append. Validates the payload client-side the way the server
 * will: it must be a JSON object, canonicalise cleanly (integers only), and
 * fit the canonical size limit. The server remains authoritative.
 */
final readonly class NewEvent
{
    public const MAX_CANONICAL_PAYLOAD_BYTES = 32768;

    public stdClass $payload;

    /**
     * The occurred_at moment as an RFC 3339 string, exactly as it will be
     * sent and hashed.
     */
    public string $occurredAt;

    /**
     * @param  array<string, mixed>|stdClass  $payload  associative array or object; pass `new stdClass` for an empty object
     * @param  DateTimeInterface|string|null  $occurredAt  defaults to now; strings pass through verbatim
     */
    public function __construct(
        public string $actor,
        public string $action,
        array|stdClass $payload,
        DateTimeInterface|string|null $occurredAt = null,
        public ?string $resource = null,
        public ?string $idempotencyKey = null,
    ) {
        $this->payload = self::normalizePayload($payload);

        $canonicalBytes = strlen(CanonicalJson::encode($this->payload));

        if ($canonicalBytes > self::MAX_CANONICAL_PAYLOAD_BYTES) {
            throw new InvalidPayloadException(
                "Canonical payload is {$canonicalBytes} bytes; the limit is ".self::MAX_CANONICAL_PAYLOAD_BYTES.' bytes.'
            );
        }

        $this->occurredAt = match (true) {
            $occurredAt instanceof DateTimeInterface => Rfc3339::micro($occurredAt),
            is_string($occurredAt) => $occurredAt,
            default => Rfc3339::micro(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
        };
    }

    /**
     * The wire shape for the append endpoints. Optional fields are omitted
     * when absent rather than sent as null.
     *
     * @return array<string, mixed>
     */
    public function toRequestBody(): array
    {
        $body = [
            'occurred_at' => $this->occurredAt,
            'actor' => $this->actor,
            'action' => $this->action,
            'payload' => $this->payload,
        ];

        if ($this->resource !== null) {
            $body['resource'] = $this->resource;
        }

        if ($this->idempotencyKey !== null) {
            $body['idempotency_key'] = $this->idempotencyKey;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>|stdClass  $payload
     */
    private static function normalizePayload(array|stdClass $payload): stdClass
    {
        if ($payload instanceof stdClass) {
            return $payload;
        }

        if ($payload === []) {
            return new stdClass;
        }

        if (array_is_list($payload)) {
            throw new InvalidPayloadException(
                'The payload must be a JSON object, not an array. Pass an associative array or stdClass; '
                .'wrap lists in a key, e.g. ["items" => [...]].'
            );
        }

        return (object) $payload;
    }
}
