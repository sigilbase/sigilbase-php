<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use RuntimeException;
use Sigilbase\Dto\Checkpoint;
use Sigilbase\Dto\Event;
use Sigilbase\Dto\InclusionProof;
use Sigilbase\Dto\PublicKey;
use Sigilbase\Dto\PublicKeySet;
use stdClass;

/**
 * Builds SDK inclusion-proof DTOs from a bundle written by the vendored
 * verifier FixtureGenerator, mapping the bundle field names (root,
 * prev_checkpoint, from, to) onto the API names the SDK uses. Audit paths
 * come from the test-side generator; the SDK only walks them.
 */
final class FixtureProofs
{
    public static function proof(string $dir, int $sequence): InclusionProof
    {
        $manifest = self::decodeFile($dir.'/manifest.json');
        $events = self::events($dir);
        $checkpoints = self::decodeFile($dir.'/checkpoints.json')->checkpoints;

        $event = $events[$sequence - 1];
        $checkpoint = null;

        foreach ($checkpoints as $candidate) {
            if ($candidate->from <= $sequence && $sequence <= $candidate->to) {
                $checkpoint = $candidate;
                break;
            }
        }

        if ($checkpoint === null) {
            throw new RuntimeException("No checkpoint covers sequence {$sequence}.");
        }

        $leaves = [];

        for ($covered = $checkpoint->from; $covered <= $checkpoint->to; $covered++) {
            $leaves[] = (string) hex2bin($events[$covered - 1]->entry_hash);
        }

        $leafIndex = $sequence - $checkpoint->from;

        return new InclusionProof(
            streamId: $manifest->stream->id,
            streamSlug: $manifest->stream->slug,
            event: new Event(
                id: sprintf('00000000-0000-7000-8000-%012d', $sequence),
                sequence: $event->seq,
                occurredAt: $event->occurred_at,
                receivedAt: $event->received_at,
                actor: $event->actor,
                action: $event->action,
                resource: $event->resource,
                payload: $event->payload,
                payloadHash: $event->payload_hash,
                prevHash: $event->prev_hash,
                entryHash: $event->entry_hash,
                checkpointId: '00000000-0000-7000-8000-999999999999',
            ),
            leafIndex: $leafIndex,
            auditPath: MerkleAuditPath::generate($leaves, $leafIndex),
            merkleRoot: $checkpoint->root,
            checkpoint: new Checkpoint(
                id: '00000000-0000-7000-8000-999999999999',
                sequenceFrom: $checkpoint->from,
                sequenceTo: $checkpoint->to,
                merkleRoot: $checkpoint->root,
                prevCheckpointHash: $checkpoint->prev_checkpoint,
                checkpointHash: $checkpoint->checkpoint_hash,
                signature: $checkpoint->signature,
                publicKey: $checkpoint->public_key,
                createdAt: $checkpoint->created_at,
            ),
        );
    }

    public static function keySet(string $dir): PublicKeySet
    {
        $manifest = self::decodeFile($dir.'/manifest.json');

        $keys = [];

        foreach ($manifest->signing_keys as $key) {
            $keys[] = new PublicKey(
                publicKey: $key->public_key,
                algorithm: 'ed25519',
                createdAt: $key->created_at,
                retiredAt: $key->retired_at,
            );
        }

        return new PublicKeySet($keys);
    }

    /**
     * @return list<stdClass>
     */
    private static function events(string $dir): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($dir.'/events.ndjson')));

        return array_values(array_map(
            static fn (string $line): stdClass => json_decode($line, flags: JSON_THROW_ON_ERROR),
            $lines,
        ));
    }

    private static function decodeFile(string $path): stdClass
    {
        return json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);
    }
}
