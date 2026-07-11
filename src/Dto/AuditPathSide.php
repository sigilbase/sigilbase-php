<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

/**
 * Which side of the current node the sibling hash sits on while walking an
 * audit path from leaf to root.
 */
enum AuditPathSide: string
{
    case Left = 'left';
    case Right = 'right';
}
