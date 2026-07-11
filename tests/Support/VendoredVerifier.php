<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use RuntimeException;

/**
 * Runs the vendored sigilbase/verifier in a clean subprocess (no composer
 * autoloader) so its global helper functions cannot collide with framework
 * helpers in the test process. See tests/Fixtures/runner.php.
 */
final class VendoredVerifier
{
    /**
     * canonical_encode() from the published verifier, applied to each value.
     *
     * @param  list<mixed>  $values
     * @return list<string>
     */
    public static function canonicalEncodeAll(array $values): array
    {
        $in = tempnam(sys_get_temp_dir(), 'sb-canon-in');
        $out = tempnam(sys_get_temp_dir(), 'sb-canon-out');

        try {
            file_put_contents($in, json_encode(
                $values,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            self::run(['canonical', $in, $out]);

            $encoded = json_decode((string) file_get_contents($out), flags: JSON_THROW_ON_ERROR);

            return array_map(
                static fn (string $b64): string => (string) base64_decode($b64, true),
                $encoded,
            );
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * merkle_root() from the published verifier over sha256("leaf-1"..n).
     */
    public static function merkleRoot(int $count): string
    {
        return trim(self::run(['merkle', $count]));
    }

    public static function writeBundle(string $dir, int $eventCount, int $perCheckpoint): void
    {
        self::run(['bundle', $dir, $eventCount, $perCheckpoint]);
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function run(array $args): string
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Fixtures/runner.php');

        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg((string) $arg);
        }

        exec($command.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Vendored verifier runner failed: '.implode("\n", $output));
        }

        return implode("\n", $output);
    }
}
