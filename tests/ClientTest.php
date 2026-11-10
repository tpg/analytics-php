<?php

use TPG\Analytics\Client;
use TPG\Analytics\Exceptions\AnalyticsException;
use TPG\Analytics\Exceptions\RequestFailedException;
use TPG\Analytics\Exceptions\TransportException;
use TPG\Analytics\Transport\FakeTransport;
use TPG\Analytics\Transport\Response;
use TPG\Analytics\Visitor;

/**
 * Make a client that sends to the given fake transport.
 */
function fakeClient(FakeTransport $transport, mixed ...$options): Client
{
    return new Client(...['key' => 'ak_test', 'endpoint' => 'https://analytics.test/api/v1/', 'flushOnShutdown' => false, 'transport' => $transport, ...$options]);
}

test('queued events are sent together when flushed', function () {
    $transport = new FakeTransport;
    $client = fakeClient($transport);
    $visitor = new Visitor(ip: '203.0.113.9', userAgent: 'Mozilla/5.0', userId: '42');

    $client->pageview('https://example.com/pricing', $visitor, referrer: 'https://google.com/');
    $client->event('signup', ['plan' => 'pro'], $visitor);
    $client->request('get', 'https://api.example.com/users/1', 200, 35, route: '/users/{user}');
    $client->screenview('Home', new Visitor(anonymousId: 'install-1', appVersion: '2.1.0'));

    expect($transport->requests)->toBeEmpty()
        ->and($client->flush())->toBeTrue()
        ->and($transport->requests)->toHaveCount(1)
        ->and($client->queued())->toBeEmpty();

    $request = $transport->requests[0];

    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://analytics.test/api/v1/events')
        ->and($request['headers'])->toMatchArray(['Authorization' => 'Bearer ak_test', 'Content-Type' => 'application/json']);

    [$pageview, $event, $apiRequest, $screenview] = $transport->events();

    expect($pageview)->toMatchArray([
        'type' => 'pageview',
        'url' => 'https://example.com/pricing',
        'referrer' => 'https://google.com/',
        'ip' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0',
        'user_id' => '42',
    ])
        ->and($pageview['uuid'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($pageview['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and($event)->toMatchArray(['type' => 'custom', 'name' => 'signup', 'properties' => ['plan' => 'pro']])
        ->and($apiRequest)->toMatchArray(['type' => 'request', 'method' => 'GET', 'status' => 200, 'duration_ms' => 35, 'route' => '/users/{user}'])
        ->and($apiRequest)->not->toHaveKey('ip')
        ->and($screenview)->toMatchArray(['type' => 'screenview', 'screen' => 'Home', 'anonymous_id' => 'install-1', 'app_version' => '2.1.0']);
});

test('a full batch is sent straight away', function () {
    $transport = new FakeTransport;
    $client = fakeClient($transport, batchSize: 2);

    $client->event('one');
    $client->event('two');
    $client->event('three');

    expect($transport->requests)->toHaveCount(1)
        ->and($transport->events())->toHaveCount(2)
        ->and($client->queued())->toHaveCount(1);
});

test('large batches are gzipped', function () {
    $transport = new FakeTransport;
    $client = fakeClient($transport);

    foreach (range(1, 20) as $page) {
        $client->pageview("https://example.com/articles/{$page}");
    }

    $client->flush();

    expect($transport->requests[0]['headers']['Content-Encoding'])->toBe('gzip')
        ->and($transport->events())->toHaveCount(20);
});

test('batches are retried after errors that might go away', function (Response|TransportException $failure) {
    $transport = new FakeTransport($failure);
    $client = fakeClient($transport);

    $client->event('signup');

    expect($client->flush())->toBeTrue()
        ->and($transport->requests)->toHaveCount(2);
})->with([
    'rate limited' => fn () => new Response(429),
    'server error' => fn () => new Response(503),
    'network error' => fn () => new TransportException('Connection refused'),
]);

test('batches the API rejects are reported and dropped without a retry', function () {
    $transport = new FakeTransport(new Response(422, '{"message":"The events.0.url field must be a valid URL."}'));
    $reported = [];
    $client = fakeClient($transport, onError: function (AnalyticsException $exception, array $batch) use (&$reported) {
        $reported[] = [$exception, $batch];
    });

    $client->pageview('not a url');

    expect($client->flush())->toBeFalse()
        ->and($transport->requests)->toHaveCount(1)
        ->and($client->queued())->toBeEmpty()
        ->and($reported)->toHaveCount(1)
        ->and($reported[0][0])->toBeInstanceOf(RequestFailedException::class)
        ->and($reported[0][0]->getMessage())->toBe('The analytics API responded with 422: The events.0.url field must be a valid URL.')
        ->and($reported[0][1])->toHaveCount(1);
});

test('batches that keep failing are reported once the retries run out', function () {
    $transport = new FakeTransport(new Response(500), new Response(500));
    $reported = 0;
    $client = fakeClient($transport, onError: function () use (&$reported) {
        $reported++;
    });

    $client->event('signup');

    expect($client->flush())->toBeFalse()
        ->and($transport->requests)->toHaveCount(2)
        ->and($reported)->toBe(1);
});

test('a throwing error handler does not break the app', function () {
    $client = fakeClient(new FakeTransport(new Response(401)), onError: fn () => throw new RuntimeException('Oops'));

    $client->event('signup');

    expect($client->flush())->toBeFalse();
});

test('a disabled client queues and sends nothing', function () {
    $transport = new FakeTransport;
    $client = fakeClient($transport, enabled: false);

    $client->event('signup');

    expect($client->queued())->toBeEmpty()
        ->and($client->flush())->toBeTrue()
        ->and($transport->requests)->toBeEmpty();
});

test('the oldest events are dropped once the queue is full', function () {
    $client = fakeClient(new FakeTransport, maxQueueSize: 2);

    $client->event('one');
    $client->event('two');
    $client->event('three');

    expect(array_map(fn ($event) => $event->name, $client->queued()))->toBe(['two', 'three']);
});

test('events without a visitor use the resolved one', function () {
    $transport = new FakeTransport;
    $client = fakeClient($transport)->resolveVisitorUsing(fn () => new Visitor(ip: '198.51.100.1'));

    $client->event('signup');
    $client->event('login', visitor: new Visitor(ip: '198.51.100.2'));
    $client->flush();

    expect(array_column($transport->events(), 'ip'))->toBe(['198.51.100.1', '198.51.100.2']);
});

test('ping returns the property settings', function () {
    $transport = new FakeTransport(new Response(200, json_encode([
        'property' => ['name' => 'Storefront', 'type' => 'website'],
        'visitor_identification' => 'persistent',
        'visitor_cookie' => '_vid',
        'max_batch_size' => 100,
    ])));

    $settings = fakeClient($transport)->ping();

    expect($settings->name)->toBe('Storefront')
        ->and($settings->persistentVisitors)->toBeTrue()
        ->and($settings->visitorCookie)->toBe('_vid')
        ->and($transport->requests[0]['url'])->toBe('https://analytics.test/api/v1/ping');
});

test('ping throws when the key is wrong', function () {
    fakeClient(new FakeTransport(new Response(401, '{"message":"Unauthenticated."}')))->ping();
})->throws(RequestFailedException::class, 'The analytics API responded with 401: Unauthenticated.');

test('the visitor is read from the request globals', function () {
    $visitor = Visitor::fromGlobals(
        server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0'],
        cookies: ['_a_vid' => '0b8f8f5e-6a9e-4d55-9a53-2c1b0c7d6e11'],
    );

    expect($visitor->toArray())->toBe([
        'ip' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0',
        'anonymous_id' => '0b8f8f5e-6a9e-4d55-9a53-2c1b0c7d6e11',
    ]);
});

test('visitor cookies that were tampered with are ignored', function () {
    expect(Visitor::fromGlobals(server: [], cookies: ['_a_vid' => 'someone-else'])->anonymousId)->toBeNull();
});
