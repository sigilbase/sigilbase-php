<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Sigilbase\Client;
use Sigilbase\Http\Transport;
use Sigilbase\Tests\Support\FakeHttpClient;
use Sigilbase\Tests\Support\FakeSleeper;
use Sigilbase\Tests\Support\LaravelTestCase;

uses(LaravelTestCase::class)->in('Feature/Laravel');

/**
 * A Client wired to the fake PSR-18 client — no network, full control.
 */
function fakeClient(FakeHttpClient $http, ?FakeSleeper $sleeper = null, int $maxRetries = 3): Client
{
    $factory = new HttpFactory;

    return new Client(new Transport(
        httpClient: $http,
        requestFactory: $factory,
        streamFactory: $factory,
        baseUrl: 'https://sigilbase.test',
        apiKey: 'sgb_testkeyid.testsecret',
        logger: new NullLogger,
        sleeper: $sleeper ?? new FakeSleeper,
        maxRetries: $maxRetries,
    ));
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function jsonResponse(int $status, array $body, array $headers = []): Response
{
    return new Response(
        $status,
        $headers + ['Content-Type' => 'application/json'],
        json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    );
}

/**
 * A raw JSON body when the exact bytes matter ({} vs [] does not survive
 * PHP arrays).
 */
function rawJsonResponse(int $status, string $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], $body);
}
