<?php

declare(strict_types=1);

namespace Sigilbase\Exceptions;

/**
 * The API returned something this SDK does not recognise — an undocumented
 * status code or a body that does not match the documented shape. Nothing
 * is swallowed: the status and raw body are attached.
 */
final class UnexpectedResponseException extends ApiException {}
