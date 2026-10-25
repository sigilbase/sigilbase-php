<?php

declare(strict_types=1);

namespace Sigilbase;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use Sigilbase\Dto\AppendedEvent;
use Sigilbase\Dto\Checkpoint;
use Sigilbase\Dto\CommitmentResult;
use Sigilbase\Dto\Event;
use Sigilbase\Dto\InclusionProof;
use Sigilbase\Dto\NewEvent;
use Sigilbase\Dto\Receipt;
use Sigilbase\Exceptions\ApiException;
use Sigilbase\Exceptions\CommitmentConflictException;
use Sigilbase\Exceptions\RegistryNotReadyException;
use Sigilbase\Exceptions\SigilbaseException;
use Sigilbase\Exceptions\UnexpectedResponseException;
use Sigilbase\Http\ApiResponse;
use Sigilbase\Http\Transport;
use Sigilbase\Internal\Json;
use Sigilbase\Receipts\AttemptLog;
use Sigilbase\Receipts\ReceiptStore;
use Sigilbase\Receipts\RequestDigest;
use stdClass;

/**
 * Operations on one stream. Obtain via {@see Client::stream()}.
 */
final class StreamClient
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $slug,
        private readonly ?ReceiptStore $receipts = null,
        private readonly ?AttemptLog $attempts = null,
    ) {}

    /**
     * Append one event. Set an idempotency key: it makes retries safe, and
     * only appends that carry one are retried automatically.
     *
     * @param  array<string, mixed>|stdClass  $payload
     */
    public function append(
        string $actor,
        string $action,
        array|stdClass $payload,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $resource = null,
        ?string $idempotencyKey = null,
    ): AppendedEvent {
        return $this->appendEvent(new NewEvent(
            actor: $actor,
            action: $action,
            payload: $payload,
            occurredAt: $occurredAt,
            resource: $resource,
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * @throws CommitmentConflictException when the stream rejects and records key reuse, and this key
     *                                     already belongs to an event with different content
     */
    public function appendEvent(NewEvent $event): AppendedEvent
    {
        $attempt = $this->startAttempt([RequestDigest::of($event)], ['idempotency_key' => $event->idempotencyKey]);

        $response = $this->send($attempt, fn (): ApiResponse => $this->transport->request(
            'POST',
            $this->path('/events'),
            body: $event->toRequestBody(),
            idempotent: $event->idempotencyKey !== null,
            passThrough: [409],
        ));

        if ($response->status === 409) {
            throw $this->conflict($attempt, $response);
        }

        $appended = AppendedEvent::fromObject($response->body, replayed: $response->status === 200);
        $this->keep($appended->receipt);
        $this->finishAttempt($attempt, $response->status, $appended->receipt, $appended->receipt === null ? null : $appended->receipt->outcome());

        return $appended;
    }

    /**
     * Submit a commitment to a registry stream (REGISTRY-SPEC Phase 3). The
     * registry decides every submission and signs a receipt for it, so a
     * refusal comes back as a result, not an exception: `outcome` says what
     * was decided, and the receipt is kept in the client's receipt store.
     * Only what was not decided throws: an authentication failure, a
     * registry with no policy yet, or one that could not decide (retried, as
     * a commitment key makes a submission safe to repeat).
     *
     * @param  array<string, mixed>|stdClass  $payload
     */
    public function commit(
        string $commitmentKey,
        string $actor,
        string $action,
        array|stdClass $payload,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $resource = null,
    ): CommitmentResult {
        $event = new NewEvent(actor: $actor, action: $action, payload: $payload, occurredAt: $occurredAt, resource: $resource);
        $attempt = $this->startAttempt([RequestDigest::of($event, $commitmentKey)], ['commitment_key' => $commitmentKey]);

        $response = $this->send($attempt, fn (): ApiResponse => $this->transport->request(
            'POST',
            $this->path('/events'),
            body: ['commitment_key' => $commitmentKey, ...$event->toRequestBody()],
            idempotent: true,
            passThrough: [403, 409, 410, 422, 423, 429],
        ));

        if (! is_string($response->body->outcome ?? null)) {
            $message = is_string($response->body->message ?? null) ? $response->body->message : "Sigilbase returned HTTP {$response->status}.";
            $exception = ($response->body->error ?? null) === 'registry_not_ready'
                ? new RegistryNotReadyException($message, $response->status, (array) $response->body)
                : new UnexpectedResponseException($message, $response->status, (array) $response->body);

            $this->finishAttempt($attempt, $response->status, null, null, $exception->getMessage());

            throw $exception;
        }

        $result = CommitmentResult::fromResponse($response->status, $response->body, $response->retryAfter);
        $this->keep($result->receipt);
        $this->finishAttempt($attempt, $response->status, $result->receipt, $result->outcome);

        return $result;
    }

    /**
     * Append up to 100 events atomically: one invalid event rejects the
     * whole batch with nothing written. Retried automatically only when
     * every event carries an idempotency key. `replayed` is true on each
     * confirmation only when the entire batch was an idempotent replay.
     *
     * @param  list<NewEvent>  $events
     * @return list<AppendedEvent>
     */
    public function appendBatch(array $events): array
    {
        $count = count($events);

        if ($count < 1 || $count > 100) {
            throw new InvalidArgumentException("Batches must hold 1 to 100 events; got {$count}.");
        }

        $idempotent = true;

        foreach ($events as $event) {
            if ($event->idempotencyKey === null) {
                $idempotent = false;
                break;
            }
        }

        $attempt = $this->startAttempt(
            array_map(static fn (NewEvent $event): ?string => RequestDigest::of($event), $events),
            ['batch' => $count],
        );

        $response = $this->send($attempt, fn (): ApiResponse => $this->transport->request(
            'POST',
            $this->path('/events:batch'),
            body: ['events' => array_map(
                static fn (NewEvent $event): array => $event->toRequestBody(),
                $events,
            )],
            idempotent: $idempotent,
            passThrough: [409],
        ));

        if ($response->status === 409) {
            throw $this->conflict($attempt, $response);
        }

        $replayed = $response->status === 200;

        $appended = array_map(
            static fn (stdClass $confirmation): AppendedEvent => AppendedEvent::fromObject($confirmation, $replayed),
            Json::objectList($response->body, 'events'),
        );

        foreach ($appended as $confirmation) {
            $this->keep($confirmation->receipt);
        }

        $this->finishAttempt($attempt, $response->status, null, $replayed ? 'replay' : 'accepted');

        return $appended;
    }

    /**
     * Iterate events lazily from `after` (exclusive) onward, fetching pages
     * of `limit` as you consume them.
     *
     * @return Generator<int, Event>
     */
    public function events(int $after = 0, int $limit = 100): Generator
    {
        if ($after < 0) {
            throw new InvalidArgumentException("after must be zero or more; got {$after}.");
        }

        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException("limit must be between 1 and 200; got {$limit}.");
        }

        while (true) {
            $response = $this->transport->request('GET', $this->path('/events'), query: [
                'after' => $after,
                'limit' => $limit,
            ]);

            foreach (Json::objectList($response->body, 'data') as $data) {
                yield Event::fromObject($data);
            }

            $nextCursor = Json::nullableInt($response->body, 'next_cursor');

            if ($nextCursor === null) {
                return;
            }

            $after = $nextCursor;
        }
    }

    public function event(int $sequence): Event
    {
        $response = $this->transport->request('GET', $this->path("/events/{$sequence}"));

        return Event::fromObject(Json::object($response->body, 'data'));
    }

    /**
     * The inclusion proof for a sealed event: everything needed to verify
     * locally with {@see Verify\ProofVerifier}. Throws
     * EventNotSealedException until the sealing run covers the event.
     */
    public function proof(int $sequence): InclusionProof
    {
        $response = $this->transport->request('GET', $this->path("/events/{$sequence}/proof"));

        return InclusionProof::fromObject($response->body);
    }

    /**
     * @return list<Checkpoint>
     */
    public function checkpoints(): array
    {
        $response = $this->transport->request('GET', $this->path('/checkpoints'));

        return array_map(
            Checkpoint::fromObject(...),
            Json::objectList($response->body, 'data'),
        );
    }

    /**
     * A key-conflict 409, its receipts kept: a single append's, or one per
     * conflicting batch item.
     */
    private function conflict(?string $attempt, ApiResponse $response): SigilbaseException
    {
        $body = $response->body;

        if (($body->error ?? null) !== 'commitment_conflict') {
            $message = is_string($body->message ?? null) ? $body->message : 'Sigilbase returned HTTP 409.';
            $this->finishAttempt($attempt, 409, null, null, $message);

            return new UnexpectedResponseException($message, 409, (array) $body);
        }

        $conflicts = isset($body->conflicts) && is_array($body->conflicts) ? $body->conflicts : [$body];
        $first = null;

        foreach ($conflicts as $conflict) {
            if ($conflict instanceof stdClass) {
                $receipt = Receipt::fromResponse($conflict);
                $this->keep($receipt);
                $first ??= $receipt;
            }
        }

        $this->finishAttempt($attempt, 409, $first, 'conflict');

        return new CommitmentConflictException(
            is_string($body->message ?? null) ? $body->message : 'This idempotency key already belongs to an event with different content.',
            get_object_vars($body),
            is_int($body->decision_seq ?? null) ? $body->decision_seq : null,
            $first,
        );
    }

    private function keep(?Receipt $receipt): void
    {
        if ($receipt !== null) {
            $this->receipts?->put($receipt);
        }
    }

    /**
     * @param  list<string|null>  $digests
     * @param  array<string, mixed>  $keys
     */
    private function startAttempt(array $digests, array $keys): ?string
    {
        if ($this->attempts === null) {
            return null;
        }

        $id = bin2hex(random_bytes(16));

        $this->attempts->started([
            'id' => $id,
            'stream' => $this->slug,
            'request_digests' => $digests,
            ...array_filter($keys, static fn (mixed $value): bool => $value !== null),
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return $id;
    }

    /**
     * @param  callable(): ApiResponse  $send
     */
    private function send(?string $attempt, callable $send): ApiResponse
    {
        try {
            return $send();
        } catch (SigilbaseException $exception) {
            $this->finishAttempt($attempt, $exception instanceof ApiException ? $exception->status : null, null, null, $exception->getMessage());

            throw $exception;
        }
    }

    private function finishAttempt(?string $attempt, ?int $status, ?Receipt $receipt, ?string $outcome, ?string $error = null): void
    {
        if ($attempt === null || $this->attempts === null) {
            return;
        }

        $this->attempts->finished($attempt, array_filter([
            'status' => $status,
            'outcome' => $outcome,
            'decision_seq' => $receipt?->decisionSeq(),
            'receipt' => $receipt?->id(),
            'error' => $error,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function path(string $suffix): string
    {
        return '/api/v1/streams/'.rawurlencode($this->slug).$suffix;
    }
}
