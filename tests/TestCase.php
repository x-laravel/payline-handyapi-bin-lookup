<?php

namespace XLaravel\Payline\BinLookup\HandyApi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Payline\BinLookup\HandyApi\HandyApiBinLookupServiceProvider;
use XLaravel\Payline\PaylineServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaylineServiceProvider::class,
            HandyApiBinLookupServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');

        $app['config']->set('payline.bin_lookup.providers', ['handyapi']);
    }
}
