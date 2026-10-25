<?php

declare(strict_types=1);

namespace Sigilbase\Verify;

use DateTimeImmutable;
use Sigilbase\Dto\Receipt;
use Sigilbase\Dto\ReceiptKeySet;
use Throwable;

/**
 * Checks a receipt the way the verifier's --receipt does on its own
 * (FORMAT.md, "Receipts"): the signature over `sigilbase-receipt/1`, a
 * newline and the canonical body, by the key it names, which must have
 * been active when it signed, never before the request was received. It
 * does not check the records the receipt names: that needs the bundles,
 * and the verifier.
 */
final class ReceiptVerifier
{
    public const KEY_UNKNOWN = 'RECEIPT_KEY_UNKNOWN';

    public const SIGNATURE = 'RECEIPT_SIGNATURE';

    public const KEY_WINDOW = 'RECEIPT_KEY_WINDOW';

    public const SHAPE = 'RECEIPT_SHAPE';

    public function verify(Receipt $receipt, ReceiptKeySet $keys): ReceiptVerification
    {
        $body = $receipt->body;
        $receivedAt = $receipt->receivedAt();
        $signedAt = $receipt->signedAt();

        if (($body->v ?? null) !== Receipt::VERSION || $receivedAt === null || $signedAt === null || $signedAt < $receivedAt) {
            return ReceiptVerification::failed(self::SHAPE, 'The receipt is not a sigilbase-receipt/1 body signed no earlier than it was received.');
        }

        $key = $keys->key($receipt->keyId());

        if ($key === null) {
            return ReceiptVerification::failed(self::KEY_UNKNOWN, "Receipt key [{$receipt->keyId()}] is not among the published receipt keys.");
        }

        $signature = base64_decode($receipt->signature, true);
        $publicKey = (string) hex2bin($key['public_key']);

        try {
            $valid = is_string($signature) && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && $publicKey !== ''
                && sodium_crypto_sign_verify_detached($signature, $receipt->signedMessage(), $publicKey);
        } catch (Throwable) {
            $valid = false;
        }

        if (! $valid) {
            return ReceiptVerification::failed(self::SIGNATURE, 'The signature does not verify: the receipt is not what Sigilbase signed.');
        }

        $createdAt = $key['created_at'] === null ? null : new DateTimeImmutable($key['created_at']);
        $retiredAt = $key['retired_at'] === null ? null : new DateTimeImmutable($key['retired_at']);

        if (($createdAt !== null && $signedAt < $createdAt) || ($retiredAt !== null && $signedAt >= $retiredAt)) {
            return ReceiptVerification::failed(self::KEY_WINDOW, 'The receipt was signed outside its key\'s active window.');
        }

        return ReceiptVerification::passed();
    }
}
