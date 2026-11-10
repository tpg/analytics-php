<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use ThePublicGood\Analytics\Laravel\AnalyticsServiceProvider;

abstract class LaravelTestCase extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AnalyticsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('analytics-client.key', 'ak_test');
        $app['config']->set('analytics-client.endpoint', 'https://analytics.test/api/v1');
        $app['config']->set('cache.default', 'array');
    }
}
