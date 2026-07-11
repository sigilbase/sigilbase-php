# Changelog

All notable changes to sigilbase-php are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows semantic versioning.

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

[0.1.0]: https://github.com/sigilbase/sigilbase-php/releases/tag/v0.1.0
