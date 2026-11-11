# thepublicgood.trace for PHP

Send page views, API requests and custom events to The Public Good Trace from your server. Nothing runs in the browser.

Requires PHP 8.5+ with the cURL extension. Works with any framework, and includes a Laravel integration.

```bash
composer require thepublicgood/trace
```

## Laravel

Add your property key to `.env`. It's on the property's **Keys** page.

```dotenv
TRACE_KEY=ak_...
```

Events go to `https://trace.thepublicgood.dev/api/v1`. If Trace is hosted elsewhere, set `TRACE_ENDPOINT` to the endpoint shown on the **Keys** page.

If the Trace app runs locally with a self-signed certificate, set `TRACE_VERIFY_SSL=false` in development. Leave it on in production.

Add the middleware to record every page view automatically:

```php
// bootstrap/app.php
use TPG\Trace\Laravel\TrackVisits;

->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [TrackVisits::class]);
})
```

Page views are successful `GET` requests that return HTML or an Inertia page. Errors, JSON, prefetches and Inertia partial reloads are skipped. For an API, set `TRACE_TRACK=requests` to record every request with its method, route pattern, status and duration instead.

Record custom events with the facade. The visitor comes from the current request:

```php
use TPG\Trace\Laravel\Trace;

Trace::event('signup', ['plan' => 'pro']);
```

Events are sent in one batch after the response has gone, so they don't slow your pages down. Events tracked in queued jobs and Artisan commands are sent when the job or command finishes.

Publish the config to change ignored paths or send signed-in users' IDs:

```bash
php artisan vendor:publish --tag=trace-client-config
```

### Testing

```php
$trace = Trace::fake();

$this->post('/signup');

expect(array_column($trace->events(), 'name'))->toContain('signup');
```

## Any PHP app

```php
use TPG\Trace\Client;
use TPG\Trace\Visitor;

$trace = new Client(key: 'ak_...');

$visitor = Visitor::fromGlobals();

$trace->pageview('https://example.com/pricing', $visitor, referrer: $_SERVER['HTTP_REFERER'] ?? null);
$trace->event('signup', ['plan' => 'pro'], $visitor);
$trace->request('GET', 'https://api.example.com/users/7', status: 200, durationMs: 35, visitor: $visitor, route: '/users/{user}');
```

If Trace is hosted elsewhere, pass the endpoint too: `new Client(key: 'ak_...', endpoint: 'https://trace.example.com/api/v1')`.

Events are queued and sent in batches of 100, and whatever is left is sent when the script ends. Call `$trace->flush()` to send them sooner, such as in a long-running worker.

### Visitors

Trace uses the visitor's IP address and user agent to work out their location and device, and to count unique visitors. It never stores them.

On properties with **persistent** visitor identification, give each visitor a first-party cookie so they are recognised when they come back:

```php
use TPG\Trace\VisitorCookie;

$settings = $trace->ping(); // Cache this.

if ($settings->persistentVisitors && $visitor->anonymousId === null) {
    $id = VisitorCookie::generate();
    setcookie($settings->visitorCookie, $id, ['expires' => time() + VisitorCookie::LIFETIME, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $visitor = $visitor->withAnonymousId($id);
}
```

Pass a user ID with `$visitor->withUserId($user->id)` to count the same person once across devices. It is hashed before it is stored.

### Errors

Tracking never throws. Batches that can't be sent are retried once after network errors, rate limits and server errors, then dropped and passed to `onError`, or PHP's error log without one:

```php
new Client(key: '...', onError: function (TraceException $exception, array $events) {
    report($exception);
});
```

Set `enabled: false` to send nothing, such as in local development.

## Testing this library

```bash
composer install
composer test
composer analyse
```
