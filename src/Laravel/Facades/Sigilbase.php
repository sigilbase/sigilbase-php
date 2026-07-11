<?php

declare(strict_types=1);

namespace Sigilbase\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Sigilbase\Client;

/**
 * Optional facade. Constructor injection of {@see Client} is the
 * recommended way in; this exists for codebases that prefer facades.
 *
 * @method static \Sigilbase\StreamClient stream(string $slug)
 * @method static \Sigilbase\Dto\PublicKeySet publicKeys()
 *
 * @see Client
 */
final class Sigilbase extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
