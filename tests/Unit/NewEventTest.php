<?php

declare(strict_types=1);

use Sigilbase\Dto\NewEvent;
use Sigilbase\Exceptions\InvalidPayloadException;

it('defaults occurred_at to now in RFC 3339 with six fractional digits', function () {
    $event = new NewEvent(actor: 'user:1', action: 'test', payload: ['ok' => true]);

    expect($event->occurredAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
});

it('converts DateTimeInterface occurred_at to UTC microseconds', function () {
    $moment = new DateTimeImmutable('2026-07-09 10:15:00.123456', new DateTimeZone('Europe/London'));

    $event = new NewEvent(actor: 'user:1', action: 'test', payload: ['ok' => true], occurredAt: $moment);

    expect($event->occurredAt)->toBe('2026-07-09T09:15:00.123456Z');
});

it('passes string occurred_at through verbatim', function () {
    $event = new NewEvent(actor: 'user:1', action: 'test', payload: ['ok' => true], occurredAt: '2026-07-09T09:15:00Z');

    expect($event->occurredAt)->toBe('2026-07-09T09:15:00Z');
});

it('treats an empty array payload as an empty object', function () {
    $event = new NewEvent(actor: 'user:1', action: 'test', payload: []);

    expect(json_encode($event->toRequestBody()))->toContain('"payload":{}');
});

it('rejects list payloads', function () {
    expect(fn () => new NewEvent(actor: 'user:1', action: 'test', payload: [1, 2, 3]))
        ->toThrow(InvalidPayloadException::class, 'JSON object');
});

it('rejects float payload values before any request is made', function () {
    expect(fn () => new NewEvent(actor: 'user:1', action: 'test', payload: ['ratio' => 0.25]))
        ->toThrow(InvalidPayloadException::class, 'integers only');
});

it('rejects payloads over the canonical size limit', function () {
    $payload = ['filler' => str_repeat('a', 32800)];

    expect(fn () => new NewEvent(actor: 'user:1', action: 'test', payload: $payload))
        ->toThrow(InvalidPayloadException::class, 'the limit is 32768 bytes');
});

it('measures the limit on canonical bytes, not source bytes', function () {
    // 32700 payload bytes of multi-byte text stay under the limit only if
    // the check runs on canonical UTF-8 bytes; "é" is two.
    $payload = ['filler' => str_repeat('é', 16380)];

    expect(fn () => new NewEvent(actor: 'user:1', action: 'test', payload: $payload))
        ->toThrow(InvalidPayloadException::class);
});

it('omits null resource and idempotency_key from the wire shape', function () {
    $event = new NewEvent(actor: 'user:1', action: 'test', payload: ['ok' => true]);

    expect($event->toRequestBody())
        ->toHaveKeys(['occurred_at', 'actor', 'action', 'payload'])
        ->not->toHaveKey('resource')
        ->not->toHaveKey('idempotency_key');
});

it('carries resource and idempotency_key when given', function () {
    $event = new NewEvent(
        actor: 'user:1',
        action: 'test',
        payload: ['ok' => true],
        resource: 'user:9f31',
        idempotencyKey: 'k-123',
    );

    expect($event->toRequestBody())
        ->toHaveKey('resource', 'user:9f31')
        ->toHaveKey('idempotency_key', 'k-123');
});
