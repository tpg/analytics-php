<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Route;
use TPG\Trace\Client;
use TPG\Trace\Laravel\Trace;
use TPG\Trace\Laravel\TrackVisits;
use TPG\Trace\Transport\FakeTransport;
use TPG\Trace\Transport\Response;

/**
 * A ping response for a property with the given visitor identification.
 */
function pingResponse(string $identification = 'cookieless'): Response
{
    return new Response(200, json_encode([
        'property' => ['name' => 'Storefront', 'type' => 'website'],
        'visitor_identification' => $identification,
        'visitor_cookie' => '_a_vid',
        'max_batch_size' => 100,
    ]));
}

beforeEach(function () {
    Route::middleware(TrackVisits::class)->group(function () {
        Route::get('/pricing', fn () => response('<h1>Pricing</h1>'));
        Route::get('/missing', fn () => abort(404));
        Route::get('/up', fn () => response('<h1>Up</h1>'));
        Route::get('/api/users/{user}', fn (string $user) => ['id' => $user]);
        Route::post('/signup', function () {
            Trace::event('signup', ['plan' => 'pro']);

            return response('<h1>Welcome</h1>');
        });
    });
});

test('page views are recorded with the visitor', function () {
    $transport = Trace::fake(pingResponse());

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0', 'Referer' => 'https://google.com/'])
        ->get('/pricing?utm_source=newsletter')
        ->assertOk()
        ->assertCookieMissing('_a_vid');

    expect($transport->events())->toHaveCount(1)
        ->and($transport->events()[0])->toMatchArray([
            'type' => 'pageview',
            'url' => 'http://localhost/pricing?utm_source=newsletter',
            'referrer' => 'https://google.com/',
            'ip' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
        ]);
});

test('requests that are not page views are skipped', function (string $uri, array $headers) {
    $transport = Trace::fake(pingResponse());

    $this->withHeaders($headers)->get($uri);

    expect($transport->events())->toBeEmpty();
})->with([
    'errors' => ['/missing', []],
    'JSON responses' => ['/api/users/1', []],
    'prefetches' => ['/pricing', ['Sec-Purpose' => 'prefetch']],
    'Inertia partial reloads' => ['/pricing', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Pricing']],
    'ignored paths' => ['/up', []],
]);

test('API properties record every request with its route pattern', function () {
    config(['trace-client.track' => 'requests']);
    $transport = Trace::fake(pingResponse());

    $this->getJson('/api/users/7')->assertOk();
    $this->getJson('/missing')->assertNotFound();

    [$found, $missing] = $transport->events();

    expect($found)->toMatchArray([
        'type' => 'request',
        'method' => 'GET',
        'url' => 'http://localhost/api/users/7',
        'route' => '/api/users/{user}',
        'status' => 200,
    ])
        ->and($found['duration_ms'])->toBeInt()
        ->and($missing)->toMatchArray(['status' => 404, 'route' => '/missing']);
});

test('persistent properties give new visitors a cookie and recognise them afterwards', function () {
    $transport = Trace::fake(pingResponse('persistent'));

    $response = $this->get('/pricing')->assertCookieNotExpired('_a_vid');
    $visitorId = $response->getCookie('_a_vid', decrypt: false)->getValue();

    $this->withUnencryptedCookie('_a_vid', $visitorId)->get('/pricing')->assertCookieMissing('_a_vid');

    expect(array_column($transport->events(), 'anonymous_id'))->toBe([$visitorId, $visitorId])
        ->and($transport->requests[0]['url'])->toEndWith('/ping');
});

test('the property settings are cached between requests', function () {
    $transport = Trace::fake(pingResponse('persistent'));

    $this->get('/pricing');
    $this->get('/pricing');

    expect(array_filter($transport->requests, fn (array $request) => str_ends_with($request['url'], '/ping')))->toHaveCount(1);
});

test('events tracked during a request belong to its visitor', function () {
    config(['trace-client.identify_users' => true]);
    $transport = Trace::fake(pingResponse());

    $this->actingAs(new GenericUser(['id' => 42]))
        ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
        ->post('/signup')
        ->assertOk();

    $signup = collect($transport->events())->firstWhere('type', 'custom');

    expect($signup)->toMatchArray([
        'name' => 'signup',
        'properties' => ['plan' => 'pro'],
        'ip' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'user_id' => '42',
    ]);
});

test('nothing is tracked without a key', function () {
    config(['trace-client.key' => null]);
    $transport = Trace::fake(pingResponse('persistent'));

    $this->get('/pricing')->assertCookieMissing('_a_vid');

    expect($transport->requests)->toBeEmpty();
});

test('events are sent when the request ran in a copy of the application, as under Octane', function () {
    $transport = new FakeTransport;
    $sandbox = clone $this->app;
    $sandbox->instance('app', $sandbox);
    $sandbox->instance(Container::class, $sandbox);
    $sandbox->instance(Client::class, new Client(
        key: 'ak_fake',
        endpoint: 'https://trace.invalid/api/v1',
        flushOnShutdown: false,
        transport: $transport,
    ));

    $sandbox->make(Client::class)->event('signup');
    $sandbox->terminate();

    expect($transport->events())->toHaveCount(1);
});
