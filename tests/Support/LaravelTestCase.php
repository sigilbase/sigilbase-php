<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use Orchestra\Testbench\TestCase;
use Sigilbase\Laravel\SigilbaseServiceProvider;

abstract class LaravelTestCase extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SigilbaseServiceProvider::class];
    }
}
