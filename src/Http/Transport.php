<?php

declare(strict_types=1);

namespace Sigilbase\Http;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sigilbase\Exceptions\AccessDeniedException;
use Sigilbase\Exceptions\AuthenticationException;
use Sigilbase\Exceptions\EventNotSealedException;
use Sigilbase\Exceptions\NotFoundException;
use Sigilbase\Exceptions\RateLimitedException;
use Sigilbase\Exceptions\RegistryNotReadyException;
use Sigilbase\Exceptions\ServerException;
use Sigilbase\Exceptions\SigilbaseException;
use Sigilbase\Exceptions\StreamArchivedException;
use Sigilbase\Exceptions\StreamPausedException;
use Sigilbase\Exceptions\StreamReservedException;
use Sigilbase\Exceptions\TenantSuspendedException;
use Sigilbase\Exceptions\TransportException;
use Sigilbase\Exceptions\UnexpectedResponseException;
use Sigilbase\Exceptions\ValidationException;
use Sigilbase\Version;
use stdClass;

/**
 * Sends JSON requests and maps the documented error catalogue onto typed
 * exceptions, one class per row, nothing swallowed.
 *
 * Retry policy: only requests that are safe to repeat are repeated — reads,
 * and appends carrying an idempotency key. Retries fire on network failure,
 * 429, and 5xx, with jittered exponential backoff capped at three retries,
 * honouring Retry-After.
 *
 * @internal
 */
final class Transport
{
    private const BACKOFF_BASE_MS = 500;

    private const BACKOFF_CAP_MS = 8000;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly Sleeper $sleeper = new SystemSleeper,
        private readonly int $maxRetries = 3,
    ) {}

    /**
     * @param  array<string, int|string>|null  $query
     * @param  array<string, mixed>|null  $body
     * @param  list<int>  $passThrough  error statuses answered with their decoded body instead of an
     *                                  exception, before any retry: a registry's recorded refusals, whose
     *                                  receipts must be read from the body exactly as signed
     */
    public function request(
        string $method,
        string $path,
        ?array $query = null,
        ?array $body = null,
        bool $idempotent = true,
        bool $authenticated = true,
        array $passThrough = [],
    ): ApiResponse {
        $uri = $this->baseUrl.$path;

        if ($query !== null && $query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $attempt = 0;

        while (true) {
            $canRetry = $idempotent && $attempt < $this->maxRetries;

            try {
                $response = $this->send($method, $uri, $body, $authenticated);
            } catch (ClientExceptionInterface $exception) {
                if (! $canRetry) {
                    throw new TransportException(
                        "The request to [{$method} {$path}] failed before a response arrived: {$exception->getMessage()}",
                        $exception,
                    );
                }

                $this->backOff($attempt, null, $method, $path, 'network error');
                $attempt++;

                continue;
            }

            $status = $response->getStatusCode();

            if (in_array($status, $passThrough, true)) {
                $decoded = $this->decodeObject($response);

                if ($decoded !== null) {
                    return new ApiResponse($status, $decoded, $this->retryAfterSeconds($response));
                }
            }

            if (($status === 429 || $status >= 500) && $canRetry) {
                $this->backOff($attempt, $this->retryAfterSeconds($response), $method, $path, "HTTP {$status}");
                $attempt++;

                continue;
            }

            if ($status >= 200 && $status < 300) {
                return new ApiResponse($status, $this->decodeSuccess($response, $method, $path));
            }

            throw $this->mapError($status, $response);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $uri, ?array $body, bool $authenticated): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'sigilbase-php/'.Version::SDK);

        if ($authenticated) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->apiKey);
        }

        if ($body !== null) {
            $encoded = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encoded));
        }

        return $this->httpClient->sendRequest($request);
    }

    private function decodeSuccess(ResponseInterface $response, string $method, string $path): stdClass
    {
        $decoded = $this->decodeObject($response);

        if ($decoded === null) {
            throw new UnexpectedResponseException(
                "The response to [{$method} {$path}] was not a JSON object.",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    private function decodeObject(ResponseInterface $response): ?stdClass
    {
        try {
            $decoded = json_decode((string) $response->getBody(), associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $decoded instanceof stdClass ? $decoded : null;
    }

    private function mapError(int $status, ResponseInterface $response): SigilbaseException
    {
        $body = $this->decodeErrorBody($response);

        $code = isset($body['error']) && is_string($body['error']) ? $body['error'] : null;
        $message = isset($body['message']) && is_string($body['message'])
            ? $body['message']
            : ($code !== null ? "The API refused the request with error code [{$code}]." : "Sigilbase returned HTTP {$status}.");

        return match (true) {
            $status === 401 => new AuthenticationException($message, 401, $body),
            $status === 403 && $code === 'stream_reserved' => new StreamReservedException(
                'Streams prefixed sigilbase- are reserved for the platform and refuse external writes (stream_reserved).',
                403,
                $body,
            ),
            $status === 403 && $code === 'tenant_suspended' => new TenantSuspendedException(
                'The account is suspended and ingestion is refused (tenant_suspended).',
                403,
                $body,
            ),
            $status === 403 => new AccessDeniedException($message, 403, $body),
            $status === 404 => new NotFoundException($message, 404, $body),
            $status === 409 && $code === 'registry_not_ready' => new RegistryNotReadyException($message, 409, $body),
            $status === 409 => new EventNotSealedException($message, 409, $body),
            $status === 410 => new StreamArchivedException(
                'The stream is archived and permanently read-only (stream_archived).',
                410,
                $body,
            ),
            $status === 422 => new ValidationException($message, $this->fieldErrors($body), $body),
            $status === 423 => new StreamPausedException(
                'The stream is paused and refusing writes; retry once it is resumed (stream_paused).',
                423,
                $body,
            ),
            $status === 429 => new RateLimitedException($message, $this->retryAfterSeconds($response), $body),
            $status >= 500 => new ServerException($message, $status, $body),
            default => new UnexpectedResponseException($message, $status, $body),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeErrorBody(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, list<string>>
     */
    private function fieldErrors(array $body): array
    {
        $errors = [];

        if (isset($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $field => $messages) {
                if (! is_array($messages)) {
                    continue;
                }

                $errors[(string) $field] = array_values(array_filter(
                    $messages,
                    static fn (mixed $message): bool => is_string($message),
                ));
            }
        }

        return $errors;
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }

    private function backOff(int $attempt, ?int $retryAfter, string $method, string $path, string $reason): void
    {
        $seconds = $retryAfter !== null
            ? (float) $retryAfter
            : random_int(0, min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * (2 ** $attempt))) / 1000;

        $this->logger->debug('Retrying Sigilbase request', [
            'method' => $method,
            'path' => $path,
            'reason' => $reason,
            'attempt' => $attempt + 1,
            'delay_seconds' => $seconds,
        ]);

        $this->sleeper->sleep($seconds);
    }
}
