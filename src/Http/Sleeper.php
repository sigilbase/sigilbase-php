<?php

declare(strict_types=1);

namespace Sigilbase\Http;

/**
 * Seam for retry backoff so tests assert delays instead of waiting them.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
