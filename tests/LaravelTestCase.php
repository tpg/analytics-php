<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use TPG\Trace\Laravel\TraceServiceProvider;

abstract class LaravelTestCase extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TraceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('trace-client.key', 'ak_test');
        $app['config']->set('trace-client.endpoint', 'https://trace.test/api/v1');
        $app['config']->set('cache.default', 'array');
    }
}
