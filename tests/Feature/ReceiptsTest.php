<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Log\NullLogger;
use Sigilbase\Client;
use Sigilbase\Dto\NewEvent;
use Sigilbase\Dto\Receipt;
use Sigilbase\Dto\ReceiptKeySet;
use Sigilbase\Exceptions\CommitmentConflictException;
use Sigilbase\Exceptions\RegistryNotReadyException;
use Sigilbase\Exceptions\ServerException;
use Sigilbase\Exceptions\TenantSuspendedException;
use Sigilbase\Exceptions\ValidationException;
use Sigilbase\Http\Transport;
use Sigilbase\Receipts\FileAttemptLog;
use Sigilbase\Receipts\FileReceiptStore;
use Sigilbase\Receipts\ReceiptStore;
use Sigilbase\Receipts\RequestDigest;
use Sigilbase\Receipts\SqliteReceiptStore;
use Sigilbase\Tests\Support\FakeHttpClient;
use Sigilbase\Tests\Support\FakeNetworkFailure;
use Sigilbase\Tests\Support\FakeSleeper;
use Sigilbase\Verify\ReceiptVerifier;

/*
 * Receipts (REGISTRY-SPEC Phase 4, SDK): every receipt the client is given
 * is kept, every request is logged with its digest, receipts verify against
 * the published receipt keys, and a registry's refusals come back as
 * results. The fixtures are the verifier corpus's own, signed with its
 * corpus receipt key.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sigilbase-receipts-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700, true);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($this->dir);
});

function receiptFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/receipts/'.$name);
}

function keepingClient(FakeHttpClient $http, string $dir): Client
{
    $factory = new HttpFactory;

    return new Client(
        new Transport(
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            baseUrl: 'https://sigilbase.test',
            apiKey: 'sgb_testkeyid.testsecret',
            logger: new NullLogger,
            sleeper: new FakeSleeper,
        ),
        new FileReceiptStore($dir.'/receipts'),
        new FileAttemptLog($dir.'/attempts.ndjson'),
    );
}

/**
 * The corpus registry's first submission, as the application received it.
 */
function acmeSubmission(): NewEvent
{
    return new NewEvent(
        actor: 'filer:acme',
        action: 'filing.submitted',
        payload: ['filing_hash' => 'sha256:'.str_repeat('aa', 32)],
        occurredAt: '2026-07-01T10:09:00+01:00',
    );
}

it('computes the request digest Sigilbase signs, before sending', function () {
    $receipt = Receipt::fromJson(receiptFixture('04-accepted.json'));

    expect(RequestDigest::of(acmeSubmission(), 'acme-q3'))->toBe($receipt->requestDigest())
        ->and(RequestDigest::of(acmeSubmission(), 'acme-q3'))->not->toBe(RequestDigest::of(acmeSubmission()));
});

it('verifies a receipt against the published receipt keys, and says why one fails', function () {
    $receipt = Receipt::fromJson(receiptFixture('04-accepted.json'));
    $keys = ReceiptKeySet::fromObject(json_decode(receiptFixture('receipt-keys.json')));
    $retired = ReceiptKeySet::fromObject(json_decode(receiptFixture('receipt-keys-retired.json')));
    $verifier = new ReceiptVerifier;

    $tamperedBody = clone $receipt->body;
    $tamperedBody->outcome = 'replay';

    expect($verifier->verify($receipt, $keys)->valid)->toBeTrue()
        ->and($verifier->verify(new Receipt($tamperedBody, $receipt->signature), $keys)->reason)->toBe(ReceiptVerifier::SIGNATURE)
        ->and($verifier->verify($receipt, $retired)->reason)->toBe(ReceiptVerifier::KEY_WINDOW)
        ->and($verifier->verify($receipt, new ReceiptKeySet([]))->reason)->toBe(ReceiptVerifier::KEY_UNKNOWN);
});

it('fetches the receipt keys unauthenticated to verify', function () {
    $http = new FakeHttpClient;
    $http->queue(rawJsonResponse(200, receiptFixture('receipt-keys.json')));

    $verification = fakeClient($http)->verifyReceipt(Receipt::fromJson(receiptFixture('04-accepted.json')));

    expect($verification->valid)->toBeTrue()
        ->and((string) $http->lastRequest()->getUri())->toBe('https://sigilbase.test/api/v1/receipt-keys')
        ->and($http->lastRequest()->hasHeader('Authorization'))->toBeFalse();
});

it('keeps the receipt of every append and logs the attempt with its digest', function () {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('04-accepted.json'));
    $http->queue(rawJsonResponse(201, json_encode([
        'id' => 'e1', 'sequence' => 1, 'entry_hash' => str_repeat('ab', 32), 'received_at' => '2026-07-01T09:10:00.000000Z',
        'receipt' => $receipt, 'receipt_pending' => false,
    ])));

    $client = keepingClient($http, $this->dir);
    $appended = $client->stream('filings')->appendEvent(acmeSubmission());

    $stored = iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false);
    $lines = (new FileAttemptLog($this->dir.'/attempts.ndjson'))->lines();

    expect($appended->receipt?->outcome())->toBe('accepted')
        ->and($appended->receiptPending)->toBeFalse()
        ->and($stored)->toHaveCount(1)
        ->and($stored[0]->id())->toBe($appended->receipt->id())
        ->and($lines[0]['event'])->toBe('started')
        ->and($lines[0]['request_digests'])->toBe([RequestDigest::of(acmeSubmission())])
        ->and($lines[1]['event'])->toBe('tried')
        ->and($lines[1]['status'])->toBe(201)
        ->and($lines[2]['event'])->toBe('finished')
        ->and($lines[2]['id'])->toBe($lines[0]['id'])
        ->and($lines[2]['receipt'])->toBe($appended->receipt->id())
        ->and($lines[2]['sequence'])->toBe(1)
        ->and($lines[2]['outcome'])->toBe('accepted')
        ->and($lines[2]['receipt_pending'])->toBeFalse();
});

it('says when a receipt is still owed', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(201, [
        'id' => 'e1', 'sequence' => 1, 'entry_hash' => str_repeat('ab', 32), 'received_at' => '2026-07-01T09:10:00.000000Z',
        'receipt' => null, 'receipt_pending' => true,
    ]));

    $appended = keepingClient($http, $this->dir)->stream('ledger')->append(actor: 'a', action: 'b', payload: []);

    expect($appended->receipt)->toBeNull()->and($appended->receiptPending)->toBeTrue();
});

it('turns a recorded key conflict into its own exception, keeping the receipt', function () {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('06-conflict.json'));
    $http->queue(rawJsonResponse(409, json_encode([
        'error' => 'commitment_conflict', 'message' => 'Reused.', 'decision_seq' => 6,
        'accepted' => ['sequence' => 1, 'entry_hash' => str_repeat('ab', 32)], 'receipt' => $receipt, 'receipt_pending' => false,
    ])));

    $client = keepingClient($http, $this->dir);

    try {
        $client->stream('ledger')->append(actor: 'a', action: 'b', payload: [], idempotencyKey: 'k');
        $this->fail('expected a conflict');
    } catch (CommitmentConflictException $conflict) {
        expect($conflict->decisionSeq)->toBe(6)
            ->and($conflict->receipt?->outcome())->toBe('conflict');
    }

    expect(iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false))->toHaveCount(1);
});

it('returns a registry refusal as a result, never discarding its receipt', function (int $status, string $outcome) {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('06-conflict.json'));
    $http->queue(rawJsonResponse($status, json_encode([
        'outcome' => $outcome, 'error' => 'x', 'message' => 'Refused.', 'reasons' => ['unknown_key'],
        'decision_seq' => 9, 'recorded' => true, 'receipt' => $receipt, 'receipt_pending' => false,
    ]), ));

    $result = keepingClient($http, $this->dir)->stream('filings')->commit('acme-q3', 'filer:acme', 'filing.submitted', ['filing_hash' => 'x']);
    $body = json_decode((string) $http->lastRequest()->getBody(), true);

    expect($result->outcome)->toBe($outcome)
        ->and($result->status)->toBe($status)
        ->and($result->decisionSeq)->toBe(9)
        ->and($result->reasons)->toBe(['unknown_key'])
        ->and($result->receipt)->not->toBeNull()
        ->and($body['commitment_key'])->toBe('acme-q3')
        ->and(iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false))->toHaveCount(1)
        ->and($http->requests)->toHaveCount(1);
})->with([
    'conflict' => [409, 'conflict'],
    'invalid' => [422, 'invalid'],
    'refused scope' => [403, 'refused_scope'],
    'paused' => [423, 'refused_paused'],
    'archived' => [410, 'refused_archived'],
    'throttled, never retried' => [429, 'throttled'],
]);

it('accepts a commitment and reports the event', function () {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('04-accepted.json'));
    $http->queue(rawJsonResponse(201, json_encode([
        'outcome' => 'accepted', 'id' => 'e1', 'sequence' => 1, 'entry_hash' => str_repeat('ab', 32),
        'received_at' => '2026-07-01T09:10:00.000000Z', 'decision_seq' => 4, 'recorded' => true, 'receipt' => $receipt, 'receipt_pending' => false,
    ])));

    $result = keepingClient($http, $this->dir)->stream('filings')->commit('acme-q3', 'filer:acme', 'filing.submitted', ['filing_hash' => 'x']);

    expect($result->accepted())->toBeTrue()
        ->and($result->sequence)->toBe(1)
        ->and($result->decisionSeq)->toBe(4);
});

it('throws only for what a registry did not decide', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(409, ['error' => 'registry_not_ready', 'message' => 'No policy yet.']));

    expect(fn () => keepingClient($http, $this->dir)->stream('filings')->commit('k', 'a', 'b', []))
        ->toThrow(RegistryNotReadyException::class);

    $http = new FakeHttpClient;

    foreach (range(1, 4) as $i) {
        $http->queue(jsonResponse(503, ['error' => 'registry_unavailable', 'message' => 'Retry.'], ['Retry-After' => '1']));
    }

    expect(fn () => keepingClient($http, $this->dir)->stream('filings')->commit('k', 'a', 'b', []))
        ->toThrow(ServerException::class);

    // A commitment key makes a submission safe to repeat: retried.
    expect($http->requests)->toHaveCount(4);

    $lines = (new FileAttemptLog($this->dir.'/attempts.ndjson'))->lines();

    expect($lines[count($lines) - 1]['event'])->toBe('finished')
        ->and($lines[count($lines) - 1]['status'])->toBe(503)
        ->and($lines[count($lines) - 1])->not->toHaveKey('receipt');
});

it('exports the receipts it kept for the verifier, and keeps each receipt once', function () {
    $store = new FileReceiptStore($this->dir.'/receipts');
    $receipt = Receipt::fromJson(receiptFixture('04-accepted.json'));
    $store->put($receipt);
    $store->put($receipt);
    $store->put(Receipt::fromJson(receiptFixture('06-conflict.json')));

    $client = new Client(
        new Transport(new FakeHttpClient, new HttpFactory, new HttpFactory, 'https://sigilbase.test', 'sgb_x.y'),
        $store,
    );

    expect($client->exportReceipts($this->dir.'/export'))->toBe(2);

    $exported = glob($this->dir.'/export/*.json');

    expect($exported)->toHaveCount(2)
        ->and(Receipt::fromJson((string) file_get_contents($exported[0]))->id())->toBe($receipt->id());
});

it('keeps receipts in SQLite too', function () {
    $store = new SqliteReceiptStore($this->dir.'/receipts.sqlite');
    $receipt = Receipt::fromJson(receiptFixture('04-accepted.json'));
    $store->put($receipt);
    $store->put($receipt);

    $all = iterator_to_array($store->all(), false);

    expect($all)->toHaveCount(1)
        ->and($all[0]->signature)->toBe($receipt->signature)
        ->and($all[0]->id())->toBe($receipt->id());
})->skip(fn () => ! extension_loaded('pdo_sqlite'), 'ext-pdo_sqlite is not loaded');

it('says plainly when a commit went to a stream that is not a registry', function () {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('04-accepted.json'));
    $http->queue(rawJsonResponse(201, json_encode([
        'id' => 'e1', 'sequence' => 7, 'entry_hash' => str_repeat('ab', 32), 'received_at' => '2026-07-01T09:10:00.000000Z',
        'receipt' => $receipt, 'receipt_pending' => false,
    ])));

    $result = keepingClient($http, $this->dir)->stream('ledger')->commit('acme-q3', 'a', 'b', []);

    expect($result->registry)->toBeFalse()
        ->and($result->sequence)->toBe(7)
        ->and($result->receipt)->not->toBeNull()
        ->and(iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false))->toHaveCount(1);
});

it('maps a commit refused before any decision like an append', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(403, ['error' => 'tenant_suspended']));

    expect(fn () => keepingClient($http, $this->dir)->stream('filings')->commit('k', 'a', 'b', []))
        ->toThrow(TenantSuspendedException::class);
});

it('keeps the receipt a registry signs for an append it refuses', function () {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('06-conflict.json'));
    $http->queue(rawJsonResponse(422, json_encode([
        'outcome' => 'invalid', 'error' => 'invalid', 'message' => 'Refused.', 'reasons' => ['unexpected_field'],
        'decision_seq' => 9, 'recorded' => true, 'receipt' => $receipt, 'receipt_pending' => false,
    ])));

    expect(fn () => keepingClient($http, $this->dir)->stream('filings')->append(actor: 'a', action: 'b', payload: []))
        ->toThrow(ValidationException::class);

    $lines = (new FileAttemptLog($this->dir.'/attempts.ndjson'))->lines();

    expect(iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false))->toHaveCount(1)
        ->and($lines[count($lines) - 1]['event'])->toBe('finished')
        ->and($lines[count($lines) - 1]['status'])->toBe(422);
});

it('still ends the attempt when the receipt store cannot write', function () {
    $http = new FakeHttpClient;
    $receipt = json_decode(receiptFixture('04-accepted.json'));
    $http->queue(rawJsonResponse(201, json_encode([
        'id' => 'e1', 'sequence' => 1, 'entry_hash' => str_repeat('ab', 32), 'received_at' => '2026-07-01T09:10:00.000000Z',
        'receipt' => $receipt, 'receipt_pending' => false,
    ])));

    $failing = new class implements ReceiptStore
    {
        public function put(Receipt $receipt): void
        {
            throw new RuntimeException('disk full');
        }

        public function all(): iterable
        {
            return [];
        }
    };

    $factory = new HttpFactory;
    $client = new Client(new Transport($http, $factory, $factory, 'https://sigilbase.test', 'sgb_x.y', sleeper: new FakeSleeper), $failing, new FileAttemptLog($this->dir.'/attempts.ndjson'));

    expect(fn () => $client->stream('ledger')->append(actor: 'a', action: 'b', payload: []))->toThrow(RuntimeException::class, 'disk full');

    $lines = (new FileAttemptLog($this->dir.'/attempts.ndjson'))->lines();

    expect(array_column($lines, 'event'))->toBe(['started', 'tried', 'finished'])
        ->and($lines[1]['status'])->toBe(201)
        ->and($lines[2]['error'])->toContain('disk full');
});

it('logs every try of an attempt, retries included', function () {
    $http = new FakeHttpClient;
    $http->queue(new FakeNetworkFailure('connection reset'));
    $http->queue(jsonResponse(200, ['id' => 'e1', 'sequence' => 1, 'entry_hash' => str_repeat('ab', 32), 'received_at' => '2026-07-01T09:10:00.000000Z']));

    keepingClient($http, $this->dir)->stream('ledger')->append(actor: 'a', action: 'b', payload: [], idempotencyKey: 'k');

    $lines = (new FileAttemptLog($this->dir.'/attempts.ndjson'))->lines();

    expect(array_column($lines, 'event'))->toBe(['started', 'tried', 'tried', 'finished'])
        ->and($lines[1])->toHaveKey('error')
        ->and($lines[2]['status'])->toBe(200);
});

it('fetches a receipt owed earlier, and keeps it', function () {
    $http = new FakeHttpClient;
    $http->queue(rawJsonResponse(200, json_encode(['receipt' => json_decode(receiptFixture('04-accepted.json')), 'receipt_pending' => false])));
    $http->queue(jsonResponse(202, ['receipt' => null, 'receipt_pending' => true]));

    $client = keepingClient($http, $this->dir);

    expect($client->stream('filings')->receipt(1)?->outcome())->toBe('accepted')
        ->and((string) $http->lastRequest()->getUri())->toBe('https://sigilbase.test/api/v1/streams/filings/events/1/receipt')
        ->and($client->stream('filings')->decisionReceipt(4))->toBeNull()
        ->and((string) $http->lastRequest()->getUri())->toBe('https://sigilbase.test/api/v1/streams/filings/decisions/4/receipt')
        ->and(iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false))->toHaveCount(1);
});

it('keeps every conflicting item of a batch, with its receipt', function () {
    $http = new FakeHttpClient;
    $conflict = json_decode(receiptFixture('06-conflict.json'));
    $accepted = json_decode(receiptFixture('04-accepted.json'));
    $http->queue(rawJsonResponse(409, json_encode([
        'error' => 'commitment_conflict', 'message' => 'Reused.',
        'conflicts' => [
            ['index' => 0, 'decision_seq' => 5, 'accepted' => ['sequence' => 1, 'entry_hash' => str_repeat('ab', 32)], 'receipt' => $conflict, 'receipt_pending' => false],
            ['index' => 2, 'decision_seq' => 6, 'accepted' => ['sequence' => 2, 'entry_hash' => str_repeat('cd', 32)], 'receipt' => $accepted, 'receipt_pending' => false],
        ],
    ])));

    try {
        keepingClient($http, $this->dir)->stream('ledger')->appendBatch([
            new NewEvent(actor: 'a', action: 'b', payload: [], idempotencyKey: 'k1'),
            new NewEvent(actor: 'a', action: 'b', payload: [], idempotencyKey: 'k2'),
            new NewEvent(actor: 'a', action: 'b', payload: [], idempotencyKey: 'k3'),
        ]);
        $this->fail('expected a conflict');
    } catch (CommitmentConflictException $exception) {
        expect($exception->receipts)->toHaveCount(2)->and($exception->decisionSeq)->toBeNull();
    }

    expect(iterator_to_array((new FileReceiptStore($this->dir.'/receipts'))->all(), false))->toHaveCount(2);
});

it('skips a file in the receipt folder that is not a receipt', function () {
    $store = new FileReceiptStore($this->dir.'/receipts');
    $store->put(Receipt::fromJson(receiptFixture('04-accepted.json')));
    file_put_contents($this->dir.'/receipts/zz-broken.json', '{not json');

    expect(iterator_to_array($store->all(), false))->toHaveCount(1);
});
