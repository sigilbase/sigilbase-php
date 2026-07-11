<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use Sigilbase\Dto\AuditPathSide;
use Sigilbase\Dto\AuditPathStep;
use Sigilbase\Verify\MerkleTree;

/**
 * Test-side audit path generator, ported from the server's MerkleTree so
 * fixture proofs can be built without the SDK generating its own paths —
 * the SDK only ever walks paths.
 */
final class MerkleAuditPath
{
    /**
     * @param  list<string>  $leafData  raw leaf bytes
     * @return list<AuditPathStep>
     */
    public static function generate(array $leafData, int $index): array
    {
        if (count($leafData) === 1) {
            return [];
        }

        $split = self::splitPoint(count($leafData));
        $left = array_slice($leafData, 0, $split);
        $right = array_slice($leafData, $split);

        if ($index < $split) {
            $path = self::generate($left, $index);
            $path[] = new AuditPathStep(bin2hex(self::root($right)), AuditPathSide::Right);
        } else {
            $path = self::generate($right, $index - $split);
            $path[] = new AuditPathStep(bin2hex(self::root($left)), AuditPathSide::Left);
        }

        return $path;
    }

    /**
     * @param  list<string>  $leafData  raw leaf bytes
     * @return string raw 32 bytes
     */
    public static function root(array $leafData): string
    {
        if (count($leafData) === 1) {
            return MerkleTree::leafHash($leafData[0]);
        }

        $split = self::splitPoint(count($leafData));

        return MerkleTree::nodeHash(
            self::root(array_slice($leafData, 0, $split)),
            self::root(array_slice($leafData, $split)),
        );
    }

    private static function splitPoint(int $count): int
    {
        $split = 1;

        while ($split * 2 < $count) {
            $split *= 2;
        }

        return $split;
    }
}
