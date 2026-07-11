<?php

declare(strict_types=1);

namespace Sigilbase\Verify;

/**
 * The outcome of local verification. When it fails, every failed check is
 * listed — the failures name what broke, not just that something did.
 */
final readonly class VerificationResult
{
    /**
     * @param  list<string>  $failures
     */
    private function __construct(
        public bool $verified,
        public array $failures,
    ) {}

    public static function pass(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<string>  $failures
     */
    public static function fail(array $failures): self
    {
        return new self(false, $failures);
    }
}
