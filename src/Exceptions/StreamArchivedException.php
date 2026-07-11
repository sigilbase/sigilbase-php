<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 410 with error code `stream_archived`: the stream is permanently
 * read-only. Archiving cannot be reversed; appends will never succeed.
 */
final class StreamArchivedException extends ApiException {}
