<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-18 test double: responses (or throwables) are queued in order and
 * every request is recorded for assertion.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function queue(ResponseInterface|Throwable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakeHttpClient queue is empty; queue more responses.');
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);

        if ($last === false) {
            throw new RuntimeException('No requests were sent.');
        }

        return $last;
    }
}
