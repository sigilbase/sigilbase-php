<?php

declare(strict_types=1);

namespace Sigilbase\Tests\Support;

use Sigilbase\Http\Sleeper;

final class FakeSleeper implements Sleeper
{
    /** @var list<float> */
    public array $delays = [];

    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
    }
}
