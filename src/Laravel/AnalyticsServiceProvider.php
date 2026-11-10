<?php

namespace TPG\Analytics\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use TPG\Analytics\Client;
use TPG\Analytics\Exceptions\AnalyticsException;
use TPG\Analytics\Visitor;
use TPG\Analytics\VisitorCookie;

class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register the analytics client.
     *
     * The client is scoped, so long-running workers like Octane and queue workers get a fresh queue
     * for each request or job.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/analytics-client.php', 'analytics-client');

        $this->app->scoped(Client::class, function (Application $app) {
            /** @var array{key: ?string, endpoint: ?string, enabled: bool, identify_users: bool} $config */
            $config = $app->make('config')->get('analytics-client');

            $client = new Client(
                key: (string) $config['key'],
                endpoint: (string) $config['endpoint'],
                flushOnShutdown: false,
                onError: fn (AnalyticsException $exception, array $batch) => Log::warning('Analytics dropped '.count($batch).' event(s).', ['exception' => $exception]),
                enabled: $config['enabled'] && filled($config['key']) && filled($config['endpoint']),
            );

            return $client->resolveVisitorUsing(function () use ($app, $config): ?Visitor {
                if ($app->runningInConsole() && ! $app->runningUnitTests()) {
                    return null;
                }

                return RequestVisitor::from($app->make(Request::class), $config['identify_users']);
            });
        });

        $this->app->alias(Client::class, 'analytics-client');
    }

    /**
     * Send queued events after each request, command and queued job.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/analytics-client.php' => config_path('analytics-client.php'),
            ], 'analytics-client-config');
        }

        EncryptCookies::except(VisitorCookie::DEFAULT_NAME);

        $this->app->terminating(fn (Container $container) => $this->flush($container));

        Event::listen([JobProcessed::class, JobFailed::class], fn () => $this->flush(Container::getInstance()));
    }

    /**
     * Send the queued events if anything was tracked.
     *
     * The container is the one handling the request, not the one the provider booted with. Octane runs each
     * request in a copy of the application, so the client the request used only exists in that copy.
     */
    protected function flush(Container $container): void
    {
        if ($container->resolved(Client::class)) {
            $container->make(Client::class)->flush();
        }
    }
}
