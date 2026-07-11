<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use Sigilbase\Client;
use Sigilbase\Exceptions\EventNotSealedException;
use Sigilbase\Verify\ProofVerifier;

/**
 * The quickstart, executed for real: append, read back, fetch the proof,
 * verify locally against the instance's published keys. Runs only when
 * pointed at a live instance:
 *
 *   SIGILBASE_INTEGRATION_BASE_URL=https://sigilbase.test \
 *   SIGILBASE_INTEGRATION_API_KEY=sgb_... \
 *   SIGILBASE_INTEGRATION_STREAM=sdk-integration \
 *   vendor/bin/pest --filter=integration
 *
 * Proofs exist only for sealed events, so the instance's sealing run must
 * fire while this test polls (locally: php artisan sigilbase:seal).
 * SIGILBASE_INTEGRATION_INSECURE=1 skips TLS verification for local
 * self-signed certificates.
 */
it('appends, reads back, fetches a proof, and verifies it locally against the published keys', function () {
    $baseUrl = (string) getenv('SIGILBASE_INTEGRATION_BASE_URL');
    $apiKey = (string) getenv('SIGILBASE_INTEGRATION_API_KEY');
    $stream = (string) getenv('SIGILBASE_INTEGRATION_STREAM');

    if ($baseUrl === '' || $apiKey === '' || $stream === '') {
        $this->markTestSkipped('Set SIGILBASE_INTEGRATION_BASE_URL, _API_KEY, and _STREAM to run the live integration test.');
    }

    $httpClient = getenv('SIGILBASE_INTEGRATION_INSECURE') === '1'
        ? new GuzzleClient(['timeout' => 30, 'verify' => false])
        : null;

    $client = Client::create(apiKey: $apiKey, baseUrl: $baseUrl, httpClient: $httpClient);

    // Append.
    $appended = $client->stream($stream)->append(
        actor: 'sdk:integration-test',
        action: 'sdk.integration.ran',
        payload: ['run_id' => bin2hex(random_bytes(8)), 'php' => PHP_VERSION],
        resource: 'sdk:php',
        idempotencyKey: 'sdk-integration-'.bin2hex(random_bytes(8)),
    );

    expect($appended->sequence)->toBeGreaterThanOrEqual(1)
        ->and($appended->entryHash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($appended->replayed)->toBeFalse();

    // Read back.
    $event = $client->stream($stream)->event($appended->sequence);

    expect($event->entryHash)->toBe($appended->entryHash)
        ->and($event->actor)->toBe('sdk:integration-test');

    // Fetch the proof, waiting out the sealing run.
    $proof = null;
    $deadline = time() + 180;

    while (time() < $deadline) {
        try {
            $proof = $client->stream($stream)->proof($appended->sequence);
            break;
        } catch (EventNotSealedException) {
            sleep(3);
        }
    }

    expect($proof)->not->toBeNull('The event was never sealed; trigger the sealing run while this test polls.');

    // Verify locally against the instance's published keys.
    $result = ProofVerifier::verify($proof, $client->publicKeys());

    expect($result->verified)->toBeTrue(implode('; ', $result->failures));
})->group('integration');
