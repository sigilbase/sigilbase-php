<?php

declare(strict_types=1);

namespace Sigilbase\Verify;

/**
 * The outcome of {@see ReceiptVerifier::verify()}: valid, or the reason
 * code the verifier would give (RECEIPT_SHAPE, RECEIPT_KEY_UNKNOWN,
 * RECEIPT_SIGNATURE, RECEIPT_KEY_WINDOW) with an explanation.
 */
final readonly class ReceiptVerification
{
    private function __construct(
        public bool $valid,
        public ?string $reason,
        public ?string $message,
    ) {}

    public static function passed(): self
    {
        return new self(true, null, null);
    }

    public static function failed(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
