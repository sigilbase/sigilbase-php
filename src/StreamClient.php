<?php

declare(strict_types=1);

namespace Sigilbase;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use Sigilbase\Dto\AppendedEvent;
use Sigilbase\Dto\Checkpoint;
use Sigilbase\Dto\Event;
use Sigilbase\Dto\InclusionProof;
use Sigilbase\Dto\NewEvent;
use Sigilbase\Http\Transport;
use Sigilbase\Internal\Json;
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

    public function appendEvent(NewEvent $event): AppendedEvent
    {
        $response = $this->transport->request(
            'POST',
            $this->path('/events'),
            body: $event->toRequestBody(),
            idempotent: $event->idempotencyKey !== null,
        );

        return AppendedEvent::fromObject($response->body, replayed: $response->status === 200);
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

        $response = $this->transport->request(
            'POST',
            $this->path('/events:batch'),
            body: ['events' => array_map(
                static fn (NewEvent $event): array => $event->toRequestBody(),
                $events,
            )],
            idempotent: $idempotent,
        );

        $replayed = $response->status === 200;

        return array_map(
            static fn (stdClass $confirmation): AppendedEvent => AppendedEvent::fromObject($confirmation, $replayed),
            Json::objectList($response->body, 'events'),
        );
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

    private function path(string $suffix): string
    {
        return '/api/v1/streams/'.rawurlencode($this->slug).$suffix;
    }
}
