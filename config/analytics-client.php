<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Property Key and Endpoint
    |--------------------------------------------------------------------------
    |
    | Create a key on the property's Keys page. The endpoint is shown beside
    | it, such as "https://analytics.example.com/api/v1".
    |
    */

    'key' => env('ANALYTICS_KEY'),

    'endpoint' => env('ANALYTICS_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Nothing is queued or sent while disabled, or while no key is set.
    |
    */

    'enabled' => (bool) env('ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Automatic Tracking
    |--------------------------------------------------------------------------
    |
    | What the TrackVisits middleware records: "pageviews" for websites, or
    | "requests" for APIs. Paths matching an "ignore" pattern are skipped.
    |
    */

    'track' => env('ANALYTICS_TRACK', 'pageviews'),

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
    | counted once across devices. Analytics hashes it before storing it.
    |
    */

    'identify_users' => (bool) env('ANALYTICS_IDENTIFY_USERS', false),

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
