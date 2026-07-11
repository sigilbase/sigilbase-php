<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class FakeNetworkFailure extends RuntimeException implements ClientExceptionInterface {}
