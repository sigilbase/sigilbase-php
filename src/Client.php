<?php

declare(strict_types=1);

namespace Sigilbase;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sigilbase\Dto\PublicKeySet;
use Sigilbase\Exceptions\MissingHttpClientException;
use Sigilbase\Http\Transport;

/**
 * The entry point. Create one per instance and API key, then address
 * streams by slug:
 *
 *     $client = Client::create(apiKey: 'sgb_...', baseUrl: 'https://app.sigilbase.io');
 *     $client->stream('admin-actions')->append(...);
 */
final class Client
{
    /**
     * @internal use {@see Client::create()}; this constructor exists for tests.
     */
    public function __construct(private readonly Transport $transport) {}

    /**
     * Any PSR-18 client and PSR-17 factories work; when omitted, Guzzle is
     * used if it is installed.
     */
    public static function create(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        int $maxRetries = 3,
    ): self {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('The Sigilbase API key must not be empty.');
        }

        if (! str_starts_with($baseUrl, 'https://') && ! str_starts_with($baseUrl, 'http://')) {
            throw new InvalidArgumentException(
                "The base URL must include a scheme, e.g. https://app.sigilbase.io; got [{$baseUrl}]."
            );
        }

        if ($maxRetries < 0) {
            throw new InvalidArgumentException('maxRetries must be zero or more.');
        }

        if ($httpClient === null && class_exists(GuzzleClient::class)) {
            $httpClient = new GuzzleClient(['timeout' => 30, 'connect_timeout' => 10]);
        }

        if ($httpClient === null) {
            throw new MissingHttpClientException(
                'No PSR-18 HTTP client is available. Install one (composer require guzzlehttp/guzzle) '
                .'or pass your own via the httpClient argument.'
            );
        }

        if (($requestFactory === null || $streamFactory === null) && class_exists(HttpFactory::class)) {
            $factory = new HttpFactory;
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }

        if ($requestFactory === null || $streamFactory === null) {
            throw new MissingHttpClientException(
                'No PSR-17 message factories are available. Install guzzlehttp/guzzle '
                .'or pass requestFactory and streamFactory explicitly.'
            );
        }

        $transport = new Transport(
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            baseUrl: rtrim($baseUrl, '/'),
            apiKey: $apiKey,
            logger: $logger ?? new NullLogger,
            maxRetries: $maxRetries,
        );

        return new self($transport);
    }

    public function stream(string $slug): StreamClient
    {
        return new StreamClient($this->transport, $slug);
    }

    /**
     * The published Ed25519 signing keys — unauthenticated by design, so a
     * verifier needs no credentials.
     */
    public function publicKeys(): PublicKeySet
    {
        $response = $this->transport->request('GET', '/api/v1/keys', authenticated: false);

        return PublicKeySet::fromObject($response->body);
    }
}
