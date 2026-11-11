<?php

namespace TPG\Trace\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use TPG\Trace\Client;
use TPG\Trace\Exceptions\TraceException;
use TPG\Trace\PropertySettings;
use TPG\Trace\VisitorCookie;

/**
 * Records page views or API requests automatically, and sets the visitor cookie for persistent properties.
 */
class TrackVisits
{
    /**
     * How long a failed settings lookup is remembered, so a slow API isn't asked on every request.
     */
    protected const int FAILED_LOOKUP_CACHE_SECONDS = 300;

    public function __construct(
        protected Client $client,
        protected Config $config,
        protected Cache $cache,
    ) {
        //
    }

    /**
     * Note when the request started, and give new visitors a cookie when the property recognises returning visitors.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('trace-client.started-at', microtime(true));

        if (! $this->enabled() || ! $this->shouldTrack($request)) {
            return $next($request);
        }

        $settings = $this->settings();

        if (! $settings->persistentVisitors) {
            return $next($request);
        }

        EncryptCookies::except($settings->visitorCookie);

        $visitorId = VisitorCookie::read($request->cookies->all(), $settings->visitorCookie);
        $isNew = $visitorId === null;
        $visitorId ??= VisitorCookie::generate();

        $request->attributes->set(RequestVisitor::ATTRIBUTE, $visitorId);

        $response = $next($request);

        if ($isNew) {
            $response->headers->setCookie(Cookie::create(
                name: $settings->visitorCookie,
                value: $visitorId,
                expire: time() + VisitorCookie::LIFETIME,
                secure: $request->isSecure(),
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }

    /**
     * Record the page view or request once the response has been sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! $this->enabled() || ! $this->shouldTrack($request)) {
            return;
        }

        if ($this->config->get('trace-client.track') === 'requests') {
            $this->client->request(
                method: $request->method(),
                url: $request->fullUrl(),
                status: $response->getStatusCode(),
                durationMs: $this->durationMs($request),
                route: $this->routePattern($request),
            );

            return;
        }

        if ($this->isPageView($request, $response)) {
            $this->client->pageview($request->fullUrl(), referrer: $request->headers->get('referer'));
        }
    }

    /**
     * Determine whether the request is a page a person looked at.
     */
    protected function isPageView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful() || $this->isPrefetch($request)) {
            return false;
        }

        if ($request->headers->has('X-Inertia')) {
            return ! $request->headers->has('X-Inertia-Partial-Component');
        }

        return ! $request->ajax() && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    /**
     * Determine whether the browser fetched the page ahead of a visit that may never happen.
     */
    protected function isPrefetch(Request $request): bool
    {
        $purpose = $request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? $request->headers->get('X-Moz');

        return str_contains(strtolower((string) $purpose), 'prefetch');
    }

    /**
     * Determine whether the request's path should be tracked.
     */
    protected function shouldTrack(Request $request): bool
    {
        /** @var list<string> $ignore */
        $ignore = $this->config->get('trace-client.ignore', []);

        return $ignore === [] || ! $request->is(...$ignore);
    }

    /**
     * Determine whether events will be sent.
     */
    protected function enabled(): bool
    {
        return $this->config->get('trace-client.enabled') && filled($this->config->get('trace-client.key'));
    }

    /**
     * Get the property's settings, cached so the API is only asked now and then.
     */
    protected function settings(): PropertySettings
    {
        $cacheKey = 'trace-client:settings:'.hash('xxh128', (string) $this->config->get('trace-client.key'));
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return PropertySettings::fromArray($cached);
        }

        try {
            $settings = $this->client->ping();
            $seconds = (int) $this->config->get('trace-client.settings_cache_seconds', 3600);
        } catch (TraceException) {
            $settings = new PropertySettings(name: '', type: '', persistentVisitors: false);
            $seconds = self::FAILED_LOOKUP_CACHE_SECONDS;
        }

        $this->cache->put($cacheKey, $settings->toArray(), $seconds);

        return $settings;
    }

    /**
     * Get the matched route's URI pattern, such as "/users/{user}".
     */
    protected function routePattern(Request $request): ?string
    {
        $uri = $request->route()?->uri();

        return $uri === null ? null : '/'.ltrim($uri, '/');
    }

    /**
     * Get how long the request took, in milliseconds.
     */
    protected function durationMs(Request $request): int
    {
        $startedAt = $request->attributes->get('trace-client.started-at');
        $startedAt = is_float($startedAt) ? $startedAt : (defined('LARAVEL_START') ? LARAVEL_START : microtime(true));

        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
