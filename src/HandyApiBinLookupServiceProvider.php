<?php

namespace XLaravel\Payline\BinLookup\HandyApi;

use Illuminate\Support\ServiceProvider;

class HandyApiBinLookupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('payline.bin_lookup')->extend('handyapi', function ($app, array $config) {
            return new HandyApiBinLookup($config);
        });
    }
}
