<?php

declare(strict_types=1);

use Sigilbase\Client;
use Sigilbase\Laravel\Facades\Sigilbase;

it('binds a configured client as a singleton', function () {
    config()->set('sigilbase.key', 'sgb_testkeyid.testsecret');
    config()->set('sigilbase.base_url', 'https://sigilbase.test');

    $client = $this->app->make(Client::class);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($this->app->make(Client::class))->toBe($client);
});

it('merges the default config', function () {
    expect(config('sigilbase.base_url'))->toBe('https://app.sigilbase.io')
        ->and(config('sigilbase.max_retries'))->toBe(3);
});

it('refuses to build a client without an API key', function () {
    config()->set('sigilbase.key', null);

    expect(fn () => $this->app->make(Client::class))
        ->toThrow(InvalidArgumentException::class, 'No Sigilbase API key is configured');
});

it('resolves the facade to the bound client', function () {
    config()->set('sigilbase.key', 'sgb_testkeyid.testsecret');

    expect(Sigilbase::getFacadeRoot())->toBe($this->app->make(Client::class));
});
