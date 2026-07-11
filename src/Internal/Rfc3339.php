<?php

declare(strict_types=1);

namespace Sigilbase\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * RFC 3339 with exactly six fractional digits, UTC, `Z` suffix — the one
 * timestamp format Sigilbase hashes commit to.
 *
 * @internal
 */
final class Rfc3339
{
    public static function micro(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u').'Z';
    }
}
