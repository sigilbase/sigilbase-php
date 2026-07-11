<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use Sigilbase\Exceptions\UnexpectedResponseException;
use Sigilbase\Internal\Json;
use stdClass;

/**
 * One step of a Merkle audit path: a sibling hash and the side it sits on.
 */
final readonly class AuditPathStep
{
    public function __construct(
        public string $hash,
        public AuditPathSide $side,
    ) {}

    /**
     * @internal
     */
    public static function fromObject(stdClass $data): self
    {
        $side = AuditPathSide::tryFrom(Json::string($data, 'side'));

        if ($side === null) {
            throw new UnexpectedResponseException(
                'Audit path sides must be "left" or "right".',
                0,
            );
        }

        return new self(
            hash: Json::string($data, 'hash'),
            side: $side,
        );
    }
}
