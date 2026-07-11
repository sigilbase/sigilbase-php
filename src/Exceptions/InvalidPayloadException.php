<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * The payload cannot be canonicalised, so the server would reject it; the
 * SDK refuses it before the request is made. The server remains
 * authoritative — passing this check does not replace its validation.
 */
final class InvalidPayloadException extends SigilbaseException {}
