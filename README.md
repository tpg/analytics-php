# thepublicood.analytics for PHP

Send page views, API requests and custom events to The Public Good Analytics from your server. Nothing runs in the browser.

Requires PHP 8.3+ with the cURL extension. Works with any framework, and includes a Laravel integration.

```bash
composer require thepublicgood/analytics
```

## Laravel

Add your property key and endpoint to `.env`. Both are on the property's **Keys** page.

```dotenv
ANALYTICS_KEY=ak_...
ANALYTICS_ENDPOINT=https://analytics.example.com/api/v1
```

Add the middleware to record every page view automatically:

```php
// bootstrap/app.php
use TPG\Analytics\Laravel\TrackVisits;

->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [TrackVisits::class]);
})
```

Page views are successful `GET` requests that return HTML or an Inertia page. Errors, JSON, prefetches and Inertia partial reloads are skipped. For an API, set `ANALYTICS_TRACK=requests` to record every request with its method, route pattern, status and duration instead.

Record custom events with the facade. The visitor comes from the current request:

```php
use TPG\Analytics\Laravel\Analytics;

Analytics::event('signup', ['plan' => 'pro']);
```

Events are sent in one batch after the response has gone, so they don't slow your pages down. Events tracked in queued jobs and Artisan commands are sent when the job or command finishes.

Publish the config to change ignored paths or send signed-in users' IDs:

```bash
php artisan vendor:publish --tag=analytics-client-config
```

### Testing

```php
$analytics = Analytics::fake();

$this->post('/signup');

expect(array_column($analytics->events(), 'name'))->toContain('signup');
```

## Any PHP app

```php
use TPG\Analytics\Client;
use TPG\Analytics\Visitor;

$analytics = new Client(
    key: 'ak_...',
    endpoint: 'https://analytics.example.com/api/v1',
);

$visitor = Visitor::fromGlobals();

$analytics->pageview('https://example.com/pricing', $visitor, referrer: $_SERVER['HTTP_REFERER'] ?? null);
$analytics->event('signup', ['plan' => 'pro'], $visitor);
$analytics->request('GET', 'https://api.example.com/users/7', status: 200, durationMs: 35, visitor: $visitor, route: '/users/{user}');
```

Events are queued and sent in batches of 100, and whatever is left is sent when the script ends. Call `$analytics->flush()` to send them sooner, such as in a long-running worker.

### Visitors

Analytics uses the visitor's IP address and user agent to work out their location and device, and to count unique visitors. It never stores them.

On properties with **persistent** visitor identification, give each visitor a first-party cookie so they are recognised when they come back:

```php
use TPG\Analytics\VisitorCookie;

$settings = $analytics->ping(); // Cache this.

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
new Client(key: '...', endpoint: '...', onError: function (AnalyticsException $exception, array $events) {
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
