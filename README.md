# sigilbase-php

The official PHP client for [Sigilbase](https://app.sigilbase.io/docs), the tamper-evident audit log. Your systems append events to named streams over a small HTTP API; every event is hash-chained, every few minutes the new events are sealed into a Merkle checkpoint signed with Ed25519. This package makes the appending a three-line story — and, the part that matters, verifies inclusion proofs **locally**, so your own code can confirm what Sigilbase sealed without trusting the API that answered.

The [docs site](https://app.sigilbase.io/docs) is the canonical reference for the API, the security model, and the verification mathematics. This README covers the SDK.

## Install

```bash
composer require sigilbase/sigilbase-php
```

Requires PHP 8.2+ with `ext-sodium`. The core has zero framework coupling: any PSR-18 client and PSR-17 factories work. If Guzzle is installed the SDK finds it on its own; otherwise pass your own via `Client::create()`.

## Plain PHP

```php
$client = Sigilbase\Client::create(
    apiKey: getenv('SIGILBASE_KEY'),
    baseUrl: 'https://app.sigilbase.io',
);

$event = $client->stream('admin-actions')->append(
    actor: 'user:42',
    action: 'role.granted',
    payload: ['role' => 'admin', 'granted_by' => 'user:42'],
    resource: 'user:9f31',                 // optional
    idempotencyKey: 'role-grant-8842',     // optional, but set one — see below
);

// $event->sequence, $event->entryHash, $event->receivedAt, $event->replayed
```

`occurred_at` defaults to now; pass a `DateTimeInterface` (converted to UTC microseconds) or an RFC 3339 string to date the event yourself.

Reading is cursor-paginated behind a lazy iterator, and everything the API serves comes back as readonly DTOs:

```php
$stream = $client->stream('admin-actions');

foreach ($stream->events(after: 0) as $event) { /* fetches pages as you go */ }

$stream->event(42);           // one event
$stream->checkpoints();       // the signed seals
$stream->appendBatch($events); // up to 100 NewEvent objects, atomic
$client->publicKeys();        // the published signing keys, no credentials needed
```

### Payload rules

Payloads are JSON objects canonicalised with RFC 8785, with one restriction: **numbers must be integers** with absolute value at most 2^53 − 1. Floats are rejected — their canonicalisation is a classic source of hash mismatches — so encode decimals as strings or scaled integers (`"amount_pence": 1250`). The size limit is 32,768 canonical bytes. The SDK enforces all of this before the request leaves your process; the server stays authoritative. Pass an associative array or `stdClass`; use `new stdClass` for an empty object, because an empty PHP array is a JSON array.

## Laravel

The bridge auto-discovers. Set `SIGILBASE_KEY` (and `SIGILBASE_BASE_URL` if you are not on app.sigilbase.io), then inject the client anywhere:

```php
public function __construct(private Sigilbase\Client $sigilbase) {}

$this->sigilbase->stream('admin-actions')->append(...);
```

Publish the config if you need to change defaults:

```bash
php artisan vendor:publish --tag=sigilbase-config
```

A `Sigilbase\Laravel\Facades\Sigilbase` facade exists for codebases that want one; constructor injection is the recommended way in.

## Verifying proofs locally

This is the differentiating feature. `ProofVerifier` recomputes the payload hash from the payload, the entry hash from the event's fields, walks the audit path to the Merkle root, recomputes the checkpoint hash, and checks the Ed25519 signature against Sigilbase's published keys — entirely on your machine, with `ext-sodium`, no network beyond fetching the proof and the keys. It deliberately duplicates the [standalone verifier](https://github.com/sigilbase/verifier)'s mathematics and is tested against the same fixture corpus.

The recipe worth putting in CI — *the proof for the event we just wrote verifies against Sigilbase's published keys*:

```php
use Sigilbase\Client;
use Sigilbase\Exceptions\EventNotSealedException;
use Sigilbase\Verify\ProofVerifier;

$client = Client::create(apiKey: getenv('SIGILBASE_KEY'), baseUrl: 'https://app.sigilbase.io');
$stream = $client->stream('deploy-log');

$appended = $stream->append(
    actor: 'ci:github',
    action: 'deploy.completed',
    payload: ['sha' => getenv('GITHUB_SHA') ?: 'unknown'],
    idempotencyKey: 'deploy-'.getenv('GITHUB_RUN_ID'),
);

// Proofs exist once the sealing run covers the event — within five minutes.
do {
    try {
        $proof = $stream->proof($appended->sequence);
        break;
    } catch (EventNotSealedException) {
        sleep(15);
    }
} while (true);

$result = ProofVerifier::verify($proof, $client->publicKeys());

if (! $result->verified) {
    fwrite(STDERR, implode(PHP_EOL, $result->failures).PHP_EOL);
    exit(1);
}
```

When this passes, your CI has checked — on its own hardware, from the proof's own bytes — that the event it wrote is sealed under a checkpoint signed by a published Sigilbase key, and that not one byte of it differs from what was sealed. When it fails, `$result->failures` names the exact check that broke.

Honesty about limits is part of the guarantee, so, plainly: this proves the sealed event is exactly what you are reading now. It does not prove the event was true when written, and it does not by itself prove the stream has no *other* history — that is what [evidence bundles and consistency proofs](https://app.sigilbase.io/docs/verification) are for.

## Receipts

Every append is answered with a receipt: Sigilbase's signed statement of what
it decided, made the moment it decided. Keep them. Give the client a receipt
store and an attempt log, and every receipt is kept (refusals included) and
every request recorded with its request digest:

```php
use Sigilbase\Receipts\FileAttemptLog;
use Sigilbase\Receipts\FileReceiptStore;

$client = Client::create(
    apiKey: getenv('SIGILBASE_KEY'),
    baseUrl: 'https://app.sigilbase.io',
    receiptStore: new FileReceiptStore(__DIR__.'/storage/sigilbase/receipts'),   // or SqliteReceiptStore
    attemptLog: new FileAttemptLog(__DIR__.'/storage/sigilbase/attempts.ndjson'),
);

$appended = $client->stream('admin-actions')->append(actor: 'user:42', action: 'role.granted', payload: ['role' => 'admin']);
$appended->receipt;          // Sigilbase\Dto\Receipt, or null with $appended->receiptPending

$client->verifyReceipt($appended->receipt)->valid;   // signature and key window, against /api/v1/receipt-keys
$client->exportReceipts('/tmp/receipts');            // the directory `verify.php --reconcile --receipts` reads
```

The attempt log shows the requests that never got a receipt apart from the
ones Sigilbase decided: no one can prove a request that never arrived, but you
can show you sent it.

A stream that rejects and records key reuse answers a conflicting append with
`CommitmentConflictException`, carrying the decision's `decisionSeq` and its
receipt.

### Registries

A registry stream accepts one commitment per key and decides, records and
receipts every submission. `commit()` returns its decision; a refusal is a
result to keep, not an exception:

```php
$result = $client->stream('filings-2026')->commit(
    commitmentKey: 'acme-2026-q3',
    actor: 'filer:acme',
    action: 'filing.submitted',
    payload: ['filing_hash' => 'sha256:9f86…'],
);

$result->outcome;      // accepted, replay, conflict, invalid, refused_early, refused_late, ...
$result->decisionSeq;  // its record in the registry's decision log
$result->receipt;      // kept in the receipt store too
```

It throws only for what the registry did not decide: authentication, a
suspended workspace, `RegistryNotReadyException` before a policy is committed,
and a registry that could not decide. A commit is retried automatically only
once the stream has answered this client as a registry, where a commitment key
makes a submission safe to repeat; until then a lost answer is yours to retry.

In Laravel, receipts go to `storage/sigilbase/receipts` and the attempt log to
`storage/sigilbase/attempts.ndjson` by default (`config/sigilbase.php`,
`receipts`).

## Retries and idempotency

Networks fail after the server has committed. Set `idempotencyKey` to a value that identifies the event in your system and retries become safe: a replay returns the original event (`$event->replayed === true`, HTTP 200) and writes nothing.

The SDK retries automatically on 429 and 5xx — up to three times, with jittered exponential backoff, honouring `Retry-After` — but **only for requests that are safe to repeat**: reads, and appends that carry an idempotency key. An append without one is never retried automatically, because the SDK cannot know whether the first attempt landed. Set one.

## Error handling

Exceptions mirror the [API error catalogue](https://app.sigilbase.io/docs/sending-events) one to one. Everything extends `Sigilbase\Exceptions\SigilbaseException`; nothing is swallowed.

| Exception | Status | When |
| --- | --- | --- |
| `AuthenticationException` | 401 | Bearer token missing, malformed, unknown, or revoked |
| `AccessDeniedException` | 403 | Key valid but not scoped to this stream, or plan-gated endpoint |
| `StreamReservedException` | 403 | Write to a reserved internal `sigilbase-*` stream |
| `TenantSuspendedException` | 403 | Account suspended; ingestion refused, reads keep working |
| `NotFoundException` | 404 | Unknown stream slug or sequence in your tenancy |
| `EventNotSealedException` | 409 | Proof requested before the sealing run covered the event |
| `StreamArchivedException` | 410 | Write to an archived stream — permanent, read-only |
| `ValidationException` | 422 | Field errors on `->errors`, keyed exactly as the API returns them |
| `StreamPausedException` | 423 | Write to a paused stream — reversible, retry after resuming |
| `RateLimitedException` | 429 | Limit exceeded; seconds to wait on `->retryAfter` |
| `ServerException` | 5xx | Sigilbase failed to handle the request |
| `TransportException` | — | The request never produced a response (DNS, timeout) |
| `InvalidPayloadException` | — | Rejected client-side before any request was made |
| `UnexpectedResponseException` | — | An undocumented status or body shape; raw body attached |

## Versioning

Semantic versioning from v0.1.0. The package targets `/api/v1`, which does not take breaking changes; a breaking API change would ship as a new prefix and a new major of this package.

## Licence

MIT. The standalone verifier remains a separate, dependency-free tool at [sigilbase/verifier](https://github.com/sigilbase/verifier); this SDK does not replace it for auditing evidence bundles.
