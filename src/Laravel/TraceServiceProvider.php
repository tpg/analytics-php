<?php

namespace TPG\Trace\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use TPG\Trace\Client;
use TPG\Trace\Exceptions\TraceException;
use TPG\Trace\Transport\CurlTransport;
use TPG\Trace\Visitor;
use TPG\Trace\VisitorCookie;

class TraceServiceProvider extends ServiceProvider
{
    /**
     * Register the Trace client.
     *
     * The client is scoped, so long-running workers like Octane and queue workers get a fresh queue
     * for each request or job.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/trace-client.php', 'trace-client');

        $this->app->scoped(Client::class, function (Application $app) {
            /** @var array{key: ?string, endpoint: ?string, enabled: bool, identify_users: bool, verify_ssl: bool} $config */
            $config = $app->make('config')->get('trace-client');

            $client = new Client(
                key: (string) $config['key'],
                endpoint: (string) $config['endpoint'] ?: Client::DEFAULT_ENDPOINT,
                flushOnShutdown: false,
                transport: new CurlTransport(verifySsl: $config['verify_ssl']),
                onError: fn (TraceException $exception, array $batch) => Log::warning('Trace dropped '.count($batch).' event(s).', ['exception' => $exception]),
                enabled: $config['enabled'] && filled($config['key']),
            );

            return $client->resolveVisitorUsing(function () use ($app, $config): ?Visitor {
                if ($app->runningInConsole() && ! $app->runningUnitTests()) {
                    return null;
                }

                return RequestVisitor::from($app->make(Request::class), $config['identify_users']);
            });
        });

        $this->app->alias(Client::class, 'trace-client');
    }

    /**
     * Send queued events after each request, command and queued job.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/trace-client.php' => config_path('trace-client.php'),
            ], 'trace-client-config');
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
