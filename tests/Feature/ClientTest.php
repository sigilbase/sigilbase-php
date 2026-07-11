<?php

declare(strict_types=1);

use Sigilbase\Dto\NewEvent;
use Sigilbase\Exceptions\RateLimitedException;
use Sigilbase\Exceptions\ServerException;
use Sigilbase\Exceptions\TransportException;
use Sigilbase\Tests\Support\FakeHttpClient;
use Sigilbase\Tests\Support\FakeNetworkFailure;
use Sigilbase\Tests\Support\FakeSleeper;

function appendConfirmation(int $sequence = 1): array
{
    return [
        'id' => '0197c9a4-1b2f-7c3d-9e4a-5f6b7c8d9e0f',
        'sequence' => $sequence,
        'entry_hash' => str_repeat('ab', 32),
        'received_at' => '2026-07-09T09:15:03.412876Z',
    ];
}

it('appends an event with the documented wire shape', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(201, appendConfirmation()));

    $appended = fakeClient($http)->stream('admin-actions')->append(
        actor: 'user:42',
        action: 'role.granted',
        payload: ['role' => 'admin'],
        occurredAt: '2026-07-09T09:15:00.000000Z',
        resource: 'user:9f31',
        idempotencyKey: 'k-123',
    );

    $request = $http->lastRequest();
    $body = json_decode((string) $request->getBody(), associative: true);

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://sigilbase.test/api/v1/streams/admin-actions/events')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer sgb_testkeyid.testsecret')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($body)->toBe([
            'occurred_at' => '2026-07-09T09:15:00.000000Z',
            'actor' => 'user:42',
            'action' => 'role.granted',
            'payload' => ['role' => 'admin'],
            'resource' => 'user:9f31',
            'idempotency_key' => 'k-123',
        ])
        ->and($appended->sequence)->toBe(1)
        ->and($appended->entryHash)->toBe(str_repeat('ab', 32))
        ->and($appended->replayed)->toBeFalse();
});

it('marks idempotent replays via the 200 status', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, appendConfirmation()));

    $appended = fakeClient($http)->stream('admin-actions')->append(
        actor: 'user:42',
        action: 'role.granted',
        payload: ['role' => 'admin'],
        idempotencyKey: 'k-123',
    );

    expect($appended->replayed)->toBeTrue();
});

it('sends empty object payloads as {} not []', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(201, appendConfirmation()));

    fakeClient($http)->stream('admin-actions')->append(
        actor: 'user:42',
        action: 'role.granted',
        payload: [],
    );

    expect((string) $http->lastRequest()->getBody())->toContain('"payload":{}');
});

it('never retries an append without an idempotency key', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(500, ['message' => 'Server Error']));
    $sleeper = new FakeSleeper;

    expect(fn () => fakeClient($http, $sleeper)->stream('admin-actions')->append(
        actor: 'user:42',
        action: 'role.granted',
        payload: ['role' => 'admin'],
    ))->toThrow(ServerException::class);

    expect($http->requests)->toHaveCount(1)
        ->and($sleeper->delays)->toBeEmpty();
});

it('retries a keyed append on 429, honouring Retry-After', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '7']));
    $http->queue(jsonResponse(201, appendConfirmation()));
    $sleeper = new FakeSleeper;

    $appended = fakeClient($http, $sleeper)->stream('admin-actions')->append(
        actor: 'user:42',
        action: 'role.granted',
        payload: ['role' => 'admin'],
        idempotencyKey: 'k-123',
    );

    expect($appended->sequence)->toBe(1)
        ->and($http->requests)->toHaveCount(2)
        ->and($sleeper->delays)->toBe([7.0]);
});

it('gives up after three retries and throws the mapped exception', function () {
    $http = new FakeHttpClient;

    foreach (range(1, 4) as $i) {
        $http->queue(jsonResponse(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '1']));
    }

    $sleeper = new FakeSleeper;

    try {
        fakeClient($http, $sleeper)->stream('admin-actions')->event(1);
        $this->fail('Expected RateLimitedException.');
    } catch (RateLimitedException $exception) {
        expect($exception->retryAfter)->toBe(1);
    }

    expect($http->requests)->toHaveCount(4)
        ->and($sleeper->delays)->toHaveCount(3);
});

it('retries reads on network failure and surfaces TransportException when exhausted', function () {
    $http = new FakeHttpClient;
    $http->queue(new FakeNetworkFailure('connection refused'));
    $http->queue(jsonResponse(200, ['data' => eventBody(1)]));

    $event = fakeClient($http)->stream('admin-actions')->event(1);

    expect($event->sequence)->toBe(1)
        ->and($http->requests)->toHaveCount(2);

    $failing = new FakeHttpClient;

    foreach (range(1, 4) as $i) {
        $failing->queue(new FakeNetworkFailure('connection refused'));
    }

    expect(fn () => fakeClient($failing)->stream('admin-actions')->event(1))
        ->toThrow(TransportException::class);

    expect($failing->requests)->toHaveCount(4);
});

it('appends batches atomically shaped and maps every confirmation', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(201, ['events' => [appendConfirmation(1), appendConfirmation(2)]]));

    $confirmations = fakeClient($http)->stream('admin-actions')->appendBatch([
        new NewEvent(actor: 'user:42', action: 'user.suspended', payload: ['reason' => 'fraud-review'], idempotencyKey: 'a'),
        new NewEvent(actor: 'user:42', action: 'user.suspended', payload: ['reason' => 'fraud-review'], idempotencyKey: 'b'),
    ]);

    $request = $http->lastRequest();
    $body = json_decode((string) $request->getBody(), associative: true);

    expect((string) $request->getUri())->toBe('https://sigilbase.test/api/v1/streams/admin-actions/events:batch')
        ->and($body['events'])->toHaveCount(2)
        ->and($confirmations)->toHaveCount(2)
        ->and($confirmations[1]->sequence)->toBe(2);
});

it('rejects batches outside 1 to 100 events client-side', function () {
    $http = new FakeHttpClient;

    expect(fn () => fakeClient($http)->stream('admin-actions')->appendBatch([]))
        ->toThrow(InvalidArgumentException::class, '1 to 100');

    expect($http->requests)->toBeEmpty();
});

it('only treats a batch as idempotent when every event carries a key', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(500, ['message' => 'Server Error']));

    $events = [
        new NewEvent(actor: 'user:1', action: 'test', payload: ['ok' => true], idempotencyKey: 'a'),
        new NewEvent(actor: 'user:1', action: 'test', payload: ['ok' => true]),
    ];

    expect(fn () => fakeClient($http)->stream('admin-actions')->appendBatch($events))
        ->toThrow(ServerException::class);

    expect($http->requests)->toHaveCount(1);
});

function eventBody(int $sequence): array
{
    return [
        'id' => sprintf('00000000-0000-7000-8000-%012d', $sequence),
        'sequence' => $sequence,
        'occurred_at' => '2026-07-09T09:15:00.000000Z',
        'received_at' => '2026-07-09T09:15:03.412876Z',
        'actor' => 'user:42',
        'action' => 'role.granted',
        'resource' => null,
        'payload' => ['role' => 'admin'],
        'payload_hash' => str_repeat('cd', 32),
        'prev_hash' => str_repeat('0', 64),
        'entry_hash' => str_repeat('ef', 32),
        'checkpoint_id' => null,
    ];
}

it('iterates events lazily across pages via next_cursor', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, ['data' => [eventBody(1), eventBody(2)], 'next_cursor' => 2]));
    $http->queue(jsonResponse(200, ['data' => [eventBody(3)], 'next_cursor' => null]));

    $events = fakeClient($http)->stream('admin-actions')->events(after: 0, limit: 2);

    // Nothing is fetched until iteration starts.
    expect($http->requests)->toBeEmpty();

    $sequences = [];

    foreach ($events as $event) {
        $sequences[] = $event->sequence;
    }

    expect($sequences)->toBe([1, 2, 3])
        ->and($http->requests)->toHaveCount(2);

    parse_str((string) parse_url((string) $http->requests[0]->getUri(), PHP_URL_QUERY), $firstQuery);
    parse_str((string) parse_url((string) $http->requests[1]->getUri(), PHP_URL_QUERY), $secondQuery);

    expect($firstQuery)->toBe(['after' => '0', 'limit' => '2'])
        ->and($secondQuery)->toBe(['after' => '2', 'limit' => '2']);
});

it('fetches a single event from its data envelope', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, ['data' => eventBody(7)]));

    $event = fakeClient($http)->stream('admin-actions')->event(7);

    expect((string) $http->lastRequest()->getUri())
        ->toBe('https://sigilbase.test/api/v1/streams/admin-actions/events/7')
        ->and($event->sequence)->toBe(7)
        ->and($event->payload)->toBeInstanceOf(stdClass::class);
});

it('preserves empty payload objects when reading events back', function () {
    $http = new FakeHttpClient;
    $raw = json_encode(['data' => eventBody(1)]);
    $raw = str_replace('"payload":{"role":"admin"}', '"payload":{"meta":{}}', (string) $raw);
    $http->queue(rawJsonResponse(200, $raw));

    $event = fakeClient($http)->stream('admin-actions')->event(1);

    expect($event->payload->meta)->toBeInstanceOf(stdClass::class);
});

it('parses an inclusion proof response, stream identity included', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, [
        'stream' => ['id' => '0197c9a4-0000-7000-8000-000000000042', 'slug' => 'admin-actions'],
        'event' => eventBody(3),
        'proof' => [
            'leaf_index' => 2,
            'audit_path' => [
                ['hash' => str_repeat('aa', 32), 'side' => 'left'],
                ['hash' => str_repeat('bb', 32), 'side' => 'right'],
            ],
            'merkle_root' => str_repeat('cc', 32),
        ],
        'checkpoint' => [
            'id' => '00000000-0000-7000-8000-000000000001',
            'sequence_from' => 1,
            'sequence_to' => 4,
            'merkle_root' => str_repeat('cc', 32),
            'prev_checkpoint_hash' => str_repeat('0', 64),
            'checkpoint_hash' => str_repeat('dd', 32),
            'signature' => str_repeat('ee', 64),
            'public_key' => str_repeat('ff', 32),
            'created_at' => '2026-07-09T09:20:00.000000Z',
        ],
    ]));

    $proof = fakeClient($http)->stream('admin-actions')->proof(3);

    expect((string) $http->lastRequest()->getUri())
        ->toBe('https://sigilbase.test/api/v1/streams/admin-actions/events/3/proof')
        ->and($proof->streamId)->toBe('0197c9a4-0000-7000-8000-000000000042')
        ->and($proof->streamSlug)->toBe('admin-actions')
        ->and($proof->event->sequence)->toBe(3)
        ->and($proof->leafIndex)->toBe(2)
        ->and($proof->auditPath)->toHaveCount(2)
        ->and($proof->auditPath[0]->side)->toBe(Sigilbase\Dto\AuditPathSide::Left)
        ->and($proof->merkleRoot)->toBe(str_repeat('cc', 32))
        ->and($proof->checkpoint->publicKey)->toBe(str_repeat('ff', 32));
});

it('lists checkpoints', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, ['data' => [[
        'id' => '00000000-0000-7000-8000-000000000001',
        'sequence_from' => 1,
        'sequence_to' => 4,
        'merkle_root' => str_repeat('12', 32),
        'prev_checkpoint_hash' => str_repeat('0', 64),
        'checkpoint_hash' => str_repeat('34', 32),
        'signature' => str_repeat('56', 64),
        'public_key' => str_repeat('78', 32),
        'created_at' => '2026-07-09T09:20:00.000000Z',
    ]]]));

    $checkpoints = fakeClient($http)->stream('admin-actions')->checkpoints();

    expect($checkpoints)->toHaveCount(1)
        ->and($checkpoints[0]->sequenceFrom)->toBe(1)
        ->and($checkpoints[0]->sequenceTo)->toBe(4);
});

it('fetches public keys without credentials', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, ['keys' => [[
        'public_key' => str_repeat('9a', 32),
        'algorithm' => 'ed25519',
        'created_at' => '2026-01-01T00:00:00.000000Z',
        'retired_at' => null,
    ]]]));

    $keys = fakeClient($http)->publicKeys();

    expect($http->lastRequest()->hasHeader('Authorization'))->toBeFalse()
        ->and((string) $http->lastRequest()->getUri())->toBe('https://sigilbase.test/api/v1/keys')
        ->and($keys->contains(str_repeat('9A', 32)))->toBeTrue()
        ->and($keys->contains(str_repeat('11', 32)))->toBeFalse();
});

it('url-encodes stream slugs', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(200, ['data' => eventBody(1)]));

    fakeClient($http)->stream('has space')->event(1);

    expect((string) $http->lastRequest()->getUri())
        ->toBe('https://sigilbase.test/api/v1/streams/has%20space/events/1');
});
