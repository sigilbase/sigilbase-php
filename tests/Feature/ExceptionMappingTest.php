<?php

declare(strict_types=1);

use Sigilbase\Exceptions\AccessDeniedException;
use Sigilbase\Exceptions\AuthenticationException;
use Sigilbase\Exceptions\EventNotSealedException;
use Sigilbase\Exceptions\NotFoundException;
use Sigilbase\Exceptions\RateLimitedException;
use Sigilbase\Exceptions\ServerException;
use Sigilbase\Exceptions\StreamArchivedException;
use Sigilbase\Exceptions\StreamPausedException;
use Sigilbase\Exceptions\StreamReservedException;
use Sigilbase\Exceptions\TenantSuspendedException;
use Sigilbase\Exceptions\UnexpectedResponseException;
use Sigilbase\Exceptions\ValidationException;
use Sigilbase\Tests\Support\FakeHttpClient;

/**
 * One test per row of the documented error catalogue, with the exact JSON
 * bodies the API returns. Reads are exercised with retries disabled so
 * 429/5xx map immediately.
 */
function expectMappedException(int $status, array $body, string $exceptionClass, array $headers = []): void
{
    $http = new FakeHttpClient;
    $http->queue(jsonResponse($status, $body, $headers));

    try {
        fakeClient($http, maxRetries: 0)->stream('admin-actions')->event(1);
        test()->fail("Expected {$exceptionClass} for HTTP {$status}.");
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf($exceptionClass);
        expect($exception->status ?? null)->toBe($status);
        expect($exception->body ?? null)->toBe($body);
    }
}

it('maps 401 missing bearer token', function () {
    expectMappedException(401, ['message' => 'Missing bearer token.'], AuthenticationException::class);
});

it('maps 401 malformed API key', function () {
    expectMappedException(401, ['message' => 'Malformed API key.'], AuthenticationException::class);
});

it('maps 401 invalid or revoked API key', function () {
    expectMappedException(401, ['message' => 'Invalid or revoked API key.'], AuthenticationException::class);
});

it('maps 403 key not scoped to the stream', function () {
    expectMappedException(
        403,
        ['message' => 'This API key is not scoped to stream [admin-actions].'],
        AccessDeniedException::class,
    );
});

it('maps 403 stream_reserved', function () {
    expectMappedException(403, ['error' => 'stream_reserved'], StreamReservedException::class);
});

it('maps 403 tenant_suspended', function () {
    expectMappedException(403, ['error' => 'tenant_suspended'], TenantSuspendedException::class);
});

it('maps 404 record not found', function () {
    expectMappedException(404, ['message' => 'Record not found.'], NotFoundException::class);
});

it('maps 409 event not sealed yet', function () {
    expectMappedException(
        409,
        ['message' => 'This event has not been sealed into a checkpoint yet; try again after the next sealing run.'],
        EventNotSealedException::class,
    );
});

it('maps 410 stream_archived', function () {
    expectMappedException(410, ['error' => 'stream_archived'], StreamArchivedException::class);
});

it('maps 422 with field errors exposed', function () {
    $body = [
        'message' => 'Non-integer numbers cannot be canonicalised; payloads must use integers only.',
        'errors' => [
            'payload' => ['Non-integer numbers cannot be canonicalised; payloads must use integers only.'],
        ],
    ];

    $http = new FakeHttpClient;
    $http->queue(jsonResponse(422, $body));

    try {
        fakeClient($http)->stream('admin-actions')->event(1);
        $this->fail('Expected ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors)->toBe($body['errors'])
            ->and($exception->getMessage())->toBe($body['message'])
            ->and($exception->status)->toBe(422);
    }
});

it('keys batch validation errors by event index', function () {
    $body = [
        'message' => 'The payload field is required and must be a JSON object.',
        'errors' => [
            'events.1.payload' => ['The payload field is required and must be a JSON object.'],
        ],
    ];

    $http = new FakeHttpClient;
    $http->queue(jsonResponse(422, $body));

    try {
        fakeClient($http)->stream('admin-actions')->event(1);
        $this->fail('Expected ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors)->toHaveKey('events.1.payload');
    }
});

it('maps 423 stream_paused', function () {
    expectMappedException(423, ['error' => 'stream_paused'], StreamPausedException::class);
});

it('maps 429 with Retry-After exposed', function () {
    $http = new FakeHttpClient;
    $http->queue(jsonResponse(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '31']));

    try {
        fakeClient($http, maxRetries: 0)->stream('admin-actions')->event(1);
        $this->fail('Expected RateLimitedException.');
    } catch (RateLimitedException $exception) {
        expect($exception->retryAfter)->toBe(31)
            ->and($exception->getMessage())->toBe('Too Many Attempts.');
    }
});

it('maps 5xx to ServerException', function () {
    expectMappedException(500, ['message' => 'Server Error'], ServerException::class);
});

it('maps undocumented statuses to UnexpectedResponseException, swallowing nothing', function () {
    expectMappedException(418, ['message' => 'short and stout'], UnexpectedResponseException::class);
});

it('survives non-JSON error bodies', function () {
    $http = new FakeHttpClient;
    $http->queue(rawJsonResponse(502, '<html>Bad Gateway</html>'));

    try {
        fakeClient($http, maxRetries: 0)->stream('admin-actions')->event(1);
        $this->fail('Expected ServerException.');
    } catch (ServerException $exception) {
        expect($exception->status)->toBe(502)
            ->and($exception->body)->toBe([]);
    }
});
