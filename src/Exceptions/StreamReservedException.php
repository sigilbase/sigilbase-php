<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 403 with error code `stream_reserved`: writes to internal `sigilbase-*`
 * streams are refused; they are written by the platform itself.
 */
final class StreamReservedException extends ApiException {}
