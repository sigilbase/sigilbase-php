<?php

declare(strict_types=1);

/**
 * Drift guard: the vendored verifier files are byte-for-byte the published
 * sigilbase/verifier v1.1.0 release (hashes of LF-normalised content, the
 * same pinning scheme the platform uses). If these fail, someone edited the
 * vendored copies — re-vendor from the tagged release instead; the SDK's
 * crypto tests only mean something against the published implementation.
 */
function pinnedVerifierFixtures(): array
{
    return [
        'tests/Fixtures/verifier/verify.php' => 'ef85a2f16d6d98f98e05db54d352be0a3cac600f6ed48e5db532a813dddf6845',
        'tests/Fixtures/verifier/tests/FixtureGenerator.php' => 'c889e4f25d9cba36a5b6d411cc8f81e37301b21097ee4f97e5e1ca54b692fb1c',
    ];
}

test('the vendored verifier fixtures match their pinned release hashes', function () {
    foreach (pinnedVerifierFixtures() as $relativePath => $expectedHash) {
        $path = dirname(__DIR__, 2).'/'.$relativePath;

        expect(is_file($path))->toBeTrue("Vendored fixture [{$relativePath}] is missing.");

        $normalised = str_replace("\r\n", "\n", (string) file_get_contents($path));

        expect(hash('sha256', $normalised))->toBe(
            $expectedHash,
            "Vendored fixture [{$relativePath}] no longer matches the published release it was vendored from.",
        );
    }
});
