<?php

declare(strict_types=1);

namespace Sigilbase\Verify;

use Sigilbase\Dto\AuditPathSide;
use Sigilbase\Dto\AuditPathStep;

/**
 * RFC 6962 hashing: leaves are SHA-256 over 0x00 || data, interior nodes
 * SHA-256 over 0x01 || left || right. Identical to the server's tree and
 * the standalone verifier; the domain-separating prefixes are what make a
 * leaf unforgeable as a node and vice versa.
 */
final class MerkleTree
{
    private const LEAF_PREFIX = "\x00";

    private const NODE_PREFIX = "\x01";

    /**
     * @param  string  $data  raw bytes
     * @return string raw 32 bytes
     */
    public static function leafHash(string $data): string
    {
        return hash('sha256', self::LEAF_PREFIX.$data, binary: true);
    }

    /**
     * @param  string  $left  raw 32 bytes
     * @param  string  $right  raw 32 bytes
     * @return string raw 32 bytes
     */
    public static function nodeHash(string $left, string $right): string
    {
        return hash('sha256', self::NODE_PREFIX.$left.$right, binary: true);
    }

    /**
     * Walk an audit path from a leaf to the root it implies.
     *
     * @param  string  $leafData  raw bytes hashed into the leaf
     * @param  list<AuditPathStep>  $path  leaf-to-root sibling hashes, hex
     * @return string|null the implied root as lowercase hex, or null when a
     *                     path hash is not 64 hex characters
     */
    public static function rootFromPath(string $leafData, array $path): ?string
    {
        $hash = self::leafHash($leafData);

        foreach ($path as $step) {
            if (strlen($step->hash) !== 64 || ! ctype_xdigit($step->hash)) {
                return null;
            }

            $sibling = (string) hex2bin(strtolower($step->hash));

            $hash = $step->side === AuditPathSide::Left
                ? self::nodeHash($sibling, $hash)
                : self::nodeHash($hash, $sibling);
        }

        return bin2hex($hash);
    }
}
