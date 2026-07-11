<?php

declare(strict_types=1);

use Sigilbase\Dto\AuditPathSide;
use Sigilbase\Dto\AuditPathStep;
use Sigilbase\Tests\Support\MerkleAuditPath;
use Sigilbase\Verify\MerkleTree;

it('walks audit paths to the same roots the published verifier computes', function () {
    foreach (range(1, 8) as $size) {
        $leaves = array_map(
            static fn (int $i): string => hash('sha256', "leaf-{$i}", true),
            range(1, $size),
        );

        $expectedRoot = Sigilbase\Tests\Support\VendoredVerifier::merkleRoot($size);

        foreach (range(0, $size - 1) as $index) {
            $path = MerkleAuditPath::generate($leaves, $index);

            expect(MerkleTree::rootFromPath($leaves[$index], $path))->toBe($expectedRoot);
        }
    }
});

it('separates leaf and node hash domains', function () {
    $data = str_repeat('x', 64);

    expect(MerkleTree::leafHash($data))
        ->not->toBe(MerkleTree::nodeHash(substr($data, 0, 32), substr($data, 32)));
});

it('returns null for a malformed path hash instead of walking garbage', function () {
    $path = [new AuditPathStep('not-hex', AuditPathSide::Left)];

    expect(MerkleTree::rootFromPath('leaf', $path))->toBeNull();
});
