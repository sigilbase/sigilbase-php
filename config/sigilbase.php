<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | An API key created under Settings → API keys, in the form
    | sgb_{key_id}.{secret}. Scope it to the streams this application
    | writes; broad keys are a liability, not a convenience.
    |
    */

    'key' => env('SIGILBASE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The root of the Sigilbase instance, without /api/v1.
    |
    */

    'base_url' => env('SIGILBASE_BASE_URL', 'https://app.sigilbase.io'),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Maximum automatic retries for idempotent requests on 429 and 5xx.
    | Appends without an idempotency key are never retried automatically.
    |
    */

    'max_retries' => (int) env('SIGILBASE_MAX_RETRIES', 3),

];
