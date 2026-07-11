<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 423 with error code `stream_paused`: the stream is paused and refusing
 * writes. Pausing is reversible; retry once the stream is resumed.
 */
final class StreamPausedException extends ApiException {}
