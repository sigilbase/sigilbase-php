# Changelog

All notable changes to sigilbase-php are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows semantic versioning.

## [0.2.0] - 2026-10-25

### Added

- Receipts: `AppendedEvent::$receipt` and `$receiptPending`; `Sigilbase\Dto\Receipt`.
- `Client::create()` options `receiptStore` and `attemptLog`, which turn both
  on outside Laravel.
- `StreamClient::receipt($sequence)` and `StreamClient::decisionReceipt($decisionSeq)`:
  fetch a receipt that came back pending, and keep it in the store.
- `ReceiptStore` with `FileReceiptStore` (default) and `SqliteReceiptStore`:
  every receipt the client is given is kept, refusals included.
- `AttemptLog` with `FileAttemptLog`: every request sent, with its request
  digest (`Sigilbase\Receipts\RequestDigest`), and what came back.
- `Client::receiptKeys()`, `Client::verifyReceipt()` (`Sigilbase\Verify\ReceiptVerifier`)
  and `Client::exportReceipts()`, which writes the directory the verifier's
  `--reconcile --receipts` reads.
- `StreamClient::commit()` for registry streams, returning a `CommitmentResult`
  for every recorded outcome; `RegistryNotReadyException`.
- Laravel: `sigilbase.receipts.path` and `sigilbase.receipts.attempt_log`.

### Changed

- Laravel: after upgrading, the client keeps receipts in
  `storage/sigilbase/receipts` and an attempt log in
  `storage/sigilbase/attempts.ndjson` by default. Set `SIGILBASE_RECEIPTS_PATH`
  or `SIGILBASE_ATTEMPT_LOG` to an empty value to turn either off.

### Fixed

- `Client::stream()` keeps one `StreamClient` per slug, so a registry that
  has answered once is retried on later commits however the stream is
  addressed (`$client->stream('x')->commit(...)` each time no longer forgets).
- A `409 commitment_conflict` on an append is `CommitmentConflictException`
  (with its decision and receipt), no longer `EventNotSealedException`.

## [0.1.0] - 2026-07-11

First release.

### Added

- `Sigilbase\Client` for the full `/api/v1` surface: append, batch append
  (atomic, ≤ 100), cursor-paginated lazy event iteration, single-event fetch,
  inclusion proofs, checkpoints, and the unauthenticated signing-key endpoint.
- Typed exceptions mirroring the API error catalogue one to one, including
  `RateLimitedException::$retryAfter` and `ValidationException::$errors`.
- Automatic retries (three, jittered, honouring `Retry-After`) on 429 and
  5xx for idempotent requests only: reads, and appends carrying an
  idempotency key.
- Client-side payload validation mirroring the server: JSON object required,
  integers only, 32,768-byte canonical limit.
- `Sigilbase\Verify\ProofVerifier`: local inclusion-proof verification —
  payload hash, entry hash, Merkle audit walk, checkpoint hash, and Ed25519
  signature, all recomputed with `ext-sodium` and tested against the
  published verifier's fixture corpus (vendored with pinned hashes).
- Laravel bridge: auto-discovered service provider, publishable
  `config/sigilbase.php`, optional facade.

[0.2.0]: https://github.com/sigilbase/sigilbase-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/sigilbase/sigilbase-php/releases/tag/v0.1.0
