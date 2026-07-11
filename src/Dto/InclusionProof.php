<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use Sigilbase\Internal\Json;
use stdClass;

/**
 * Everything needed to check, without trusting Sigilbase, that one event is
 * covered by a signed checkpoint: the event, the audit path from its entry
 * hash to the Merkle root, the checkpoint that signed that root, and the
 * stream identity the hashes commit to.
 */
final readonly class InclusionProof
{
    /**
     * @param  list<AuditPathStep>  $auditPath
     */
    public function __construct(
        public string $streamId,
        public string $streamSlug,
        public Event $event,
        public int $leafIndex,
        public array $auditPath,
        public string $merkleRoot,
        public Checkpoint $checkpoint,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $response): self
    {
        $stream = Json::object($response, 'stream');
        $proof = Json::object($response, 'proof');

        return new self(
            streamId: Json::string($stream, 'id'),
            streamSlug: Json::string($stream, 'slug'),
            event: Event::fromObject(Json::object($response, 'event')),
            leafIndex: Json::int($proof, 'leaf_index'),
            auditPath: array_map(
                AuditPathStep::fromObject(...),
                Json::objectList($proof, 'audit_path'),
            ),
            merkleRoot: Json::string($proof, 'merkle_root'),
            checkpoint: Checkpoint::fromObject(Json::object($response, 'checkpoint')),
        );
    }
}
