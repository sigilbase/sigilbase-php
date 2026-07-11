<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * 409: the event exists but has not been sealed into a checkpoint yet, so
 * no inclusion proof exists. Sealing runs every few minutes; retry after
 * the next run.
 */
final class EventNotSealedException extends ApiException {}
