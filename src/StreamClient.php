<?php

declare(strict_types=1);

namespace Sigilbase;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sigilbase\Dto\AppendedEvent;
use Sigilbase\Dto\Checkpoint;
use Sigilbase\Dto\CommitmentResult;
use Sigilbase\Dto\Event;
use Sigilbase\Dto\InclusionProof;
use Sigilbase\Dto\NewEvent;
use Sigilbase\Dto\Receipt;
use Sigilbase\Exceptions\ApiException;
use Sigilbase\Exceptions\CommitmentConflictException;
use Sigilbase\Exceptions\SigilbaseException;
use Sigilbase\Http\ApiResponse;
use Sigilbase\Http\Transport;
use Sigilbase\Internal\Json;
use Sigilbase\Receipts\AttemptLog;
use Sigilbase\Receipts\ReceiptStore;
use Sigilbase\Receipts\RequestDigest;
use stdClass;
use Throwable;

/**
 * Operations on one stream. Obtain via {@see Client::stream()}.
 */
final class StreamClient
{
    /**
     * Whether this stream answered as a registry, once a commit has said:
     * only then is a commit retried automatically, since only a registry
     * makes resubmitting a commitment key safe.
     */
    private ?bool $registry = null;

    /** The status of the latest HTTP response in the attempt under way. */
    private ?int $lastStatus = null;

    /** @var array<string, true> attempts whose finished line is written */
    private array $finished = [];

    /**
     * @internal
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $slug,
        private readonly ?ReceiptStore $receipts = null,
        private readonly ?AttemptLog $attempts = null,
        private readonly LoggerInterface $logger = new NullLogger,
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

        return $this->attempt($attempt, function () use ($attempt, $event): AppendedEvent {
            $response = $this->transport->request(
                'POST',
                $this->path('/events'),
                body: $event->toRequestBody(),
                idempotent: $event->idempotencyKey !== null,
                passThrough: [409],
                observe: $this->observer($attempt),
            );

            if ($response->status === 409) {
                $this->finishConflict($attempt, $response);

                throw $this->conflict($response);
            }

            $appended = AppendedEvent::fromObject($response->body, replayed: $response->status === 200);
            $this->finishAttempt($attempt, $response->status, [
                'outcome' => $appended->receipt?->outcome() ?? ($appended->replayed ? 'replay' : 'accepted'),
                'sequence' => $appended->sequence,
                'receipt' => $appended->receipt?->id(),
                'receipt_pending' => $appended->receiptPending,
            ]);

            return $appended;
        });
    }

    /**
     * Submit a commitment to a registry stream (REGISTRY-SPEC Phase 3). The
     * registry decides every submission and signs a receipt for it, so a
     * refusal comes back as a result, not an exception: `outcome` says what
     * was decided, and the receipt is kept in the client's receipt store.
     * Only what was not decided throws: an authentication failure, a
     * suspended workspace, a registry with no policy yet, or one that could
     * not decide (retried, as a commitment key makes a submission safe to
     * repeat).
     *
     * Sent to a stream that is not a registry, the commitment key is ignored
     * and the event is appended like any other: the result says so
     * (`registry` false), with the event and its receipt. Such a stream does
     * not make a resubmission safe; send to registries only.
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

        return $this->attempt($attempt, function () use ($attempt, $commitmentKey, $event): CommitmentResult {
            $response = $this->transport->request(
                'POST',
                $this->path('/events'),
                body: ['commitment_key' => $commitmentKey, ...$event->toRequestBody()],
                // Safe to repeat only on a registry: until this stream has
                // answered as one, a lost answer is the caller's to retry.
                idempotent: $this->registry === true,
                passThrough: [403, 409, 410, 422, 423, 429],
                observe: $this->observer($attempt),
            );

            if (! is_string($response->body->outcome ?? null)) {
                if ($response->status >= 200 && $response->status < 300) {
                    // A standard stream: appended, its key ignored.
                    $this->registry = false;
                    $result = CommitmentResult::fromStandardAppend($response->status, $response->body);
                } else {
                    throw $this->transport->exceptionFor($response);
                }
            } else {
                $this->registry = true;
                $result = CommitmentResult::fromResponse($response->status, $response->body, $response->retryAfter);
            }

            $this->finishAttempt($attempt, $response->status, [
                'outcome' => $result->outcome,
                'decision_seq' => $result->decisionSeq,
                'sequence' => $result->sequence,
                'receipt' => $result->receipt?->id(),
                'receipt_pending' => $result->receipt === null && ($response->body->receipt_pending ?? false) === true,
            ]);

            return $result;
        });
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

        return $this->attempt($attempt, function () use ($attempt, $events, $idempotent): array {
            $response = $this->transport->request(
                'POST',
                $this->path('/events:batch'),
                body: ['events' => array_map(
                    static fn (NewEvent $event): array => $event->toRequestBody(),
                    $events,
                )],
                idempotent: $idempotent,
                passThrough: [409],
                observe: $this->observer($attempt),
            );

            if ($response->status === 409) {
                $this->finishConflict($attempt, $response);

                throw $this->conflict($response);
            }

            $replayed = $response->status === 200;

            $appended = array_map(
                static fn (stdClass $confirmation): AppendedEvent => AppendedEvent::fromObject($confirmation, $replayed),
                Json::objectList($response->body, 'events'),
            );

            $this->finishAttempt($attempt, $response->status, [
                'outcomes' => array_map(static fn (AppendedEvent $confirmation): string => $confirmation->receipt?->outcome() ?? ($confirmation->replayed ? 'replay' : 'accepted'), $appended),
                'sequences' => array_map(static fn (AppendedEvent $confirmation): int => $confirmation->sequence, $appended),
                'receipts' => array_map(static fn (AppendedEvent $confirmation): ?string => $confirmation->receipt?->id(), $appended),
            ]);

            return $appended;
        });
    }

    /**
     * The stored receipt for an event, as GET .../events/{sequence}/receipt
     * serves it: the same bytes on every fetch. Null while its signature is
     * still owed (receipt_pending). A receipt fetched is kept like any other.
     */
    public function receipt(int $sequence): ?Receipt
    {
        return $this->fetchReceipt($this->path("/events/{$sequence}/receipt"));
    }

    /**
     * The receipt for a recorded decision, as GET .../decisions/{seq}/receipt
     * serves it; null while its signature is owed.
     */
    public function decisionReceipt(int $decisionSeq): ?Receipt
    {
        return $this->fetchReceipt($this->path("/decisions/{$decisionSeq}/receipt"));
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
     * A key-conflict 409. Its receipts were kept as the response arrived
     * (the observer); this builds the exception, with every conflict's
     * receipt when a batch conflicted. Any other 409 is mapped as the
     * transport maps it (registry_not_ready, for one).
     */
    private function conflict(ApiResponse $response): SigilbaseException
    {
        $body = $response->body;

        if (($body->error ?? null) !== 'commitment_conflict') {
            return $this->transport->exceptionFor($response);
        }

        $receipts = [];

        foreach (self::conflictBodies($body) as $conflict) {
            $receipt = Receipt::fromResponse($conflict);

            if ($receipt !== null) {
                $receipts[] = $receipt;
            }
        }

        return new CommitmentConflictException(
            is_string($body->message ?? null) ? $body->message : 'This idempotency key already belongs to an event with different content.',
            get_object_vars($body),
            is_int($body->decision_seq ?? null) ? $body->decision_seq : null,
            $receipts[0] ?? null,
            $receipts,
        );
    }

    /**
     * The objects of a response that may carry a receipt: the body itself,
     * or each conflict of a batch.
     *
     * @return list<stdClass>
     */
    private static function conflictBodies(stdClass $body): array
    {
        if (isset($body->conflicts) && is_array($body->conflicts)) {
            return array_values(array_filter($body->conflicts, static fn (mixed $conflict): bool => $conflict instanceof stdClass));
        }

        if (isset($body->events) && is_array($body->events)) {
            return array_values(array_filter($body->events, static fn (mixed $confirmation): bool => $confirmation instanceof stdClass));
        }

        return [$body];
    }

    private function fetchReceipt(string $path): ?Receipt
    {
        $response = $this->transport->request('GET', $path);
        $receipt = Receipt::fromResponse($response->body);

        if ($receipt !== null && $this->receipts !== null) {
            $this->safely('receipt store', fn () => $this->receipts->put($receipt));
        }

        return $receipt;
    }

    /**
     * Local bookkeeping (the receipt store, the attempt log) never fails a
     * request Sigilbase has already decided: that would hand the caller an
     * exception for a write that happened, and invite a retry that
     * duplicates it. The receipt is still on the result the caller gets;
     * the failure is logged as an error, and noted in the attempt log when
     * that still works.
     *
     * @param  callable(): mixed  $write
     */
    private function safely(string $what, callable $write, ?string $attempt = null): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            $this->logger->error("Sigilbase {$what} could not be written; the request itself succeeded", [
                'stream' => $this->slug,
                'exception' => $exception->getMessage(),
            ]);

            if ($attempt !== null && $what !== 'attempt log' && $this->attempts !== null) {
                try {
                    $this->attempts->tried($attempt, ['local_error' => "{$what}: {$exception->getMessage()}"]);
                } catch (Throwable) {
                    // Both local stores failing is already logged above.
                }
            }
        }
    }

    /**
     * Called by the transport for every HTTP try of an attempt, retries
     * included: logs the try, then keeps any receipt the response carries,
     * whatever its status, before the response is mapped or retried. A
     * registry signs a receipt for a refusal too (a 422, a recorded 429).
     *
     * @return \Closure(?int, ?stdClass, ?string): void
     */
    private function observer(?string $attempt): \Closure
    {
        return function (?int $status, ?stdClass $body, ?string $error) use ($attempt): void {
            $this->lastStatus = $status ?? $this->lastStatus;

            if ($attempt !== null && $this->attempts !== null) {
                $this->safely('attempt log', fn () => $this->attempts->tried($attempt, array_filter([
                    'status' => $status,
                    'error' => $error,
                ], static fn (mixed $value): bool => $value !== null)));
            }

            $store = $this->receipts;

            if ($body === null || $store === null) {
                return;
            }

            foreach (self::conflictBodies($body) as $carrier) {
                $receipt = Receipt::fromResponse($carrier);

                if ($receipt !== null) {
                    $this->safely('receipt store', fn () => $store->put($receipt), $attempt);
                }
            }
        };
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
        $this->lastStatus = null;

        $this->safely('attempt log', fn () => $this->attempts->started([
            'id' => $id,
            'stream' => $this->slug,
            'request_digests' => $digests,
            ...array_filter($keys, static fn (mixed $value): bool => $value !== null),
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]));

        return $id;
    }

    /**
     * Run an attempt: whatever ends it, an answer or anything thrown on the
     * way (a mapped error, a malformed body, a receipt store that cannot
     * write), its finished line is written first. An attempt that never
     * finishes in the log is one this process never saw end.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function attempt(?string $attempt, callable $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            $status = $exception instanceof ApiException && $exception->status > 0 ? $exception->status : $this->lastStatus;

            $this->finishAttempt($attempt, $status, [
                'error' => $exception::class.': '.$exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * A recorded key conflict's finished line: each conflict's decision_seq
     * and whether its receipt is still owed, so a pending one can be
     * fetched later with decisionReceipt(). Any other 409 is not a conflict
     * and finishes as the error it maps to.
     */
    private function finishConflict(?string $attempt, ApiResponse $response): void
    {
        $body = $response->body;

        if (($body->error ?? null) !== 'commitment_conflict') {
            return;
        }

        $this->finishAttempt($attempt, 409, [
            'outcome' => 'conflict',
            'conflicts' => array_map(static fn (stdClass $conflict): array => array_filter([
                'index' => is_int($conflict->index ?? null) ? $conflict->index : null,
                'decision_seq' => is_int($conflict->decision_seq ?? null) ? $conflict->decision_seq : null,
                'receipt_pending' => ($conflict->receipt_pending ?? false) === true,
            ], static fn (mixed $value): bool => $value !== null), self::conflictBodies($body)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function finishAttempt(?string $attempt, ?int $status, array $outcome): void
    {
        if ($attempt === null || $this->attempts === null || isset($this->finished[$attempt])) {
            return;
        }

        $this->finished[$attempt] = true;

        $this->safely('attempt log', fn () => $this->attempts->finished($attempt, array_filter(
            ['status' => $status, ...$outcome],
            static fn (mixed $value): bool => $value !== null,
        )));
    }

    private function path(string $suffix): string
    {
        return '/api/v1/streams/'.rawurlencode($this->slug).$suffix;
    }
}
