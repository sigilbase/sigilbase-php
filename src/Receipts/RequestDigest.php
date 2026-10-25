<?php

declare(strict_types=1);

namespace Sigilbase\Receipts;

use DateTimeImmutable;
use Exception;
use Sigilbase\Canonical\CanonicalJson;
use Sigilbase\Dto\NewEvent;
use Sigilbase\Internal\Rfc3339;

/**
 * The request digest (FORMAT.md, "Receipts"), computed before a request is
 * sent, exactly as Sigilbase computes it on arrival: SHA-256 of the
 * canonical form of occurred_at (normalised to UTC with microseconds),
 * actor, action, resource, the payload's hash and the commitment key. A
 * receipt for the request carries the same digest, so an attempt and its
 * receipt can be matched.
 */
final class RequestDigest
{
    public static function of(NewEvent $event, ?string $commitmentKey = null): ?string
    {
        try {
            $occurredAt = Rfc3339::micro(new DateTimeImmutable($event->occurredAt));
        } catch (Exception) {
            // Sigilbase refuses an unreadable time, so no digest of it exists.
            return null;
        }

        return 'sha256:'.hash('sha256', CanonicalJson::encode([
            'occurred_at' => $occurredAt,
            'actor' => $event->actor,
            'action' => $event->action,
            'resource' => $event->resource,
            'payload_sha256' => hash('sha256', CanonicalJson::encode($event->payload)),
            'commitment_key' => $commitmentKey,
        ]));
    }
}
