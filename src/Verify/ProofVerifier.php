<?php

declare(strict_types=1);

namespace Sigilbase\Verify;

use Sigilbase\Canonical\CanonicalJson;
use Sigilbase\Dto\InclusionProof;
use Sigilbase\Dto\PublicKeySet;
use Sigilbase\Exceptions\InvalidPayloadException;

/**
 * Verifies an inclusion proof entirely locally — no network, no trust in
 * the API that served it. Four things are recomputed from the proof's own
 * bytes:
 *
 * 1. the payload hash, from the payload content;
 * 2. the entry hash, from the event's fields (v1 preimage);
 * 3. the Merkle root, by walking the audit path from the entry hash;
 * 4. the checkpoint hash, from the checkpoint's fields — then the Ed25519
 *    signature over its raw bytes, against a published signing key.
 *
 * If any recomputation disagrees with what the API served, verification
 * fails and the result names the exact check that broke. This duplicates
 * the standalone verifier's mathematics deliberately; both are tested
 * against the same fixture corpus.
 */
final class ProofVerifier
{
    public static function verify(InclusionProof $proof, PublicKeySet $keys): VerificationResult
    {
        $failures = [];
        $event = $proof->event;
        $checkpoint = $proof->checkpoint;

        // Field shapes first: everything downstream feeds hex2bin.
        foreach ([
            'event payload_hash' => $event->payloadHash,
            'event prev_hash' => $event->prevHash,
            'event entry_hash' => $event->entryHash,
            'proof merkle_root' => $proof->merkleRoot,
            'checkpoint merkle_root' => $checkpoint->merkleRoot,
            'checkpoint prev_checkpoint_hash' => $checkpoint->prevCheckpointHash,
            'checkpoint checkpoint_hash' => $checkpoint->checkpointHash,
        ] as $label => $hash) {
            if (! self::isHex($hash, 64)) {
                $failures[] = "{$label} is not a 64-character hex hash";
            }
        }

        if (! self::isHex($checkpoint->signature, 128)) {
            $failures[] = 'checkpoint signature is not a 128-character hex Ed25519 signature';
        }

        if (! self::isHex($checkpoint->publicKey, 64)) {
            $failures[] = 'checkpoint public_key is not a 64-character hex Ed25519 key';
        }

        if ($failures !== []) {
            return VerificationResult::fail($failures);
        }

        // 1. Payload hash: recompute from the payload content itself.
        try {
            $payloadHash = hash('sha256', CanonicalJson::encode($event->payload));

            if (! hash_equals(strtolower($event->payloadHash), $payloadHash)) {
                $failures[] = 'payload_hash does not match the payload content — the payload was modified';
            }
        } catch (InvalidPayloadException $exception) {
            $failures[] = 'the payload cannot be canonicalised: '.$exception->getMessage();
        }

        // 2. Entry hash: recompute the v1 preimage from the event's fields.
        $entryPreimage = CanonicalJson::encode((object) [
            'v' => 1,
            'stream' => $proof->streamId,
            'seq' => $event->sequence,
            'occurred_at' => $event->occurredAt,
            'received_at' => $event->receivedAt,
            'actor' => $event->actor,
            'action' => $event->action,
            'resource' => $event->resource,
            'payload_hash' => $event->payloadHash,
            'prev' => $event->prevHash,
        ]);

        if (! hash_equals(strtolower($event->entryHash), hash('sha256', $entryPreimage))) {
            $failures[] = 'entry_hash does not recompute from the event fields — a field was modified';
        }

        // 3. The event must sit where the proof claims inside the checkpoint.
        if ($event->sequence < $checkpoint->sequenceFrom || $event->sequence > $checkpoint->sequenceTo) {
            $failures[] = "sequence {$event->sequence} is outside the checkpoint range {$checkpoint->sequenceFrom}..{$checkpoint->sequenceTo}";
        }

        if ($proof->leafIndex !== $event->sequence - $checkpoint->sequenceFrom) {
            $failures[] = 'leaf_index does not match the event position within the checkpoint range';
        }

        // 4. Audit path: walk from the entry hash to the root it implies.
        $impliedRoot = MerkleTree::rootFromPath(
            (string) hex2bin(strtolower($event->entryHash)),
            $proof->auditPath,
        );

        if ($impliedRoot === null) {
            $failures[] = 'the audit path contains a hash that is not a 64-character hex hash';
        } elseif (! hash_equals(strtolower($proof->merkleRoot), $impliedRoot)) {
            $failures[] = 'the audit path does not lead from the entry hash to the Merkle root';
        }

        if (! hash_equals(strtolower($checkpoint->merkleRoot), strtolower($proof->merkleRoot))) {
            $failures[] = 'the proof Merkle root does not match the checkpoint Merkle root';
        }

        // 5. Checkpoint hash: recompute the v1 preimage.
        $checkpointPreimage = CanonicalJson::encode((object) [
            'v' => 1,
            'stream' => $proof->streamId,
            'from' => $checkpoint->sequenceFrom,
            'to' => $checkpoint->sequenceTo,
            'root' => $checkpoint->merkleRoot,
            'prev_checkpoint' => $checkpoint->prevCheckpointHash,
            'created_at' => $checkpoint->createdAt,
        ]);

        $declaredCheckpointHash = strtolower($checkpoint->checkpointHash);

        if (! hash_equals($declaredCheckpointHash, hash('sha256', $checkpointPreimage))) {
            $failures[] = 'checkpoint_hash does not recompute from the checkpoint fields — the checkpoint was modified';
        }

        // 6. Signature: Ed25519 over the raw checkpoint hash bytes, by a
        // key in the published set.
        $publicKeyHex = strtolower($checkpoint->publicKey);

        if (! $keys->contains($publicKeyHex)) {
            $failures[] = 'the checkpoint is signed by a key that is not in the published signing keys';
        }

        $signatureBytes = hex2bin(strtolower($checkpoint->signature));
        $messageBytes = hex2bin($declaredCheckpointHash);
        $publicKeyBytes = hex2bin($publicKeyHex);

        $signatureValid = is_string($signatureBytes) && $signatureBytes !== ''
            && is_string($messageBytes) && $messageBytes !== ''
            && is_string($publicKeyBytes) && $publicKeyBytes !== ''
            && sodium_crypto_sign_verify_detached($signatureBytes, $messageBytes, $publicKeyBytes);

        if (! $signatureValid) {
            $failures[] = 'the Ed25519 signature does not verify — the signature is forged or the checkpoint was modified';
        }

        return $failures === [] ? VerificationResult::pass() : VerificationResult::fail($failures);
    }

    private static function isHex(string $value, int $length): bool
    {
        return strlen($value) === $length && ctype_xdigit($value);
    }
}
