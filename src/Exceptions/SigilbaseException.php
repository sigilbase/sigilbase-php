<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

use RuntimeException;

/**
 * Base class for every exception this SDK throws. Catch this to handle
 * anything Sigilbase-related; catch the specific subclasses to react to
 * individual API error responses.
 */
abstract class SigilbaseException extends RuntimeException {}
