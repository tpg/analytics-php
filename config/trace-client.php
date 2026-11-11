<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Property Key and Endpoint
    |--------------------------------------------------------------------------
    |
    | Create a key on the property's Keys page. Only set the endpoint when
    | Trace is hosted somewhere else. It is shown beside the key, such as
    | "https://trace.example.com/api/v1".
    |
    */

    'key' => env('TRACE_KEY'),

    'endpoint' => env('TRACE_ENDPOINT', 'https://trace.thepublicgood.dev/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Nothing is queued or sent while disabled, or while no key is set.
    |
    */

    'enabled' => (bool) env('TRACE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Verify SSL
    |--------------------------------------------------------------------------
    |
    | Turn this off in local development when the Trace app is served
    | with a self-signed certificate. Always leave it on in production.
    |
    */

    'verify_ssl' => (bool) env('TRACE_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Automatic Tracking
    |--------------------------------------------------------------------------
    |
    | What the TrackVisits middleware records: "pageviews" for websites, or
    | "requests" for APIs. Paths matching an "ignore" pattern are skipped.
    |
    */

    'track' => env('TRACE_TRACK', 'pageviews'),

    'ignore' => [
        'up',
        'horizon*',
        'telescope*',
        'pulse*',
        '_debugbar*',
        'livewire*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Identify Users
    |--------------------------------------------------------------------------
    |
    | Send the signed-in user's ID with each event, so the same person is
    | counted once across devices. Trace hashes it before storing it.
    |
    */

    'identify_users' => (bool) env('TRACE_IDENTIFY_USERS', false),

    /*
    |--------------------------------------------------------------------------
    | Property Settings Cache
    |--------------------------------------------------------------------------
    |
    | The property's visitor identification mode is fetched from the API and
    | cached for this many seconds. It decides whether a cookie is set.
    |
    */

    'settings_cache_seconds' => 3600,

];
