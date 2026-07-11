<?php

declare(strict_types=1);

/**
 * CLI bridge to the vendored sigilbase/verifier implementation. Tests run
 * it in a fresh PHP process WITHOUT the composer autoloader, because the
 * verifier's global helper functions (report, ...) collide with framework
 * helpers loaded in the test process. The vendored files stay byte-for-byte
 * identical to the published release; this file is the adapter around them.
 *
 * Modes:
 *   canonical <in.json> <out.json>  in: JSON array of documents;
 *                                   out: JSON array of base64(canonical_encode(doc))
 *   merkle <count>                  prints hex merkle_root of leaves
 *                                   sha256("leaf-1"..), raw, in order
 *   bundle <dir> <events> <perCheckpoint>  writes a fixture bundle
 */
require __DIR__.'/verifier/verify.php';

$mode = $argv[1] ?? '';

switch ($mode) {
    case 'canonical':
        $documents = json_decode((string) file_get_contents($argv[2]), flags: JSON_THROW_ON_ERROR);
        $encoded = [];

        foreach ($documents as $document) {
            $encoded[] = base64_encode(canonical_encode($document));
        }

        file_put_contents($argv[3], json_encode($encoded, JSON_THROW_ON_ERROR));
        break;

    case 'merkle':
        $leaves = [];

        for ($i = 1; $i <= (int) $argv[2]; $i++) {
            $leaves[] = hash('sha256', "leaf-{$i}", true);
        }

        echo bin2hex(merkle_root($leaves)), PHP_EOL;
        break;

    case 'bundle':
        require __DIR__.'/verifier/tests/FixtureGenerator.php';

        $generator = new Sigilbase\Verifier\Tests\FixtureGenerator;
        $generator->writeBundle($argv[2], (int) $argv[3], (int) $argv[4]);
        break;

    default:
        fwrite(STDERR, "unknown mode [{$mode}]\n");
        exit(2);
}
