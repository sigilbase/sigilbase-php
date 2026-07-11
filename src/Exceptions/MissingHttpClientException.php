<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * No PSR-18 HTTP client could be found. Install one (`composer require
 * guzzlehttp/guzzle` is the simplest) or pass your own to Client::create().
 */
final class MissingHttpClientException extends SigilbaseException {}
