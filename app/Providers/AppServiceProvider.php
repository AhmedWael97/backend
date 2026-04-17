<?php

namespace App\Providers;

use App\Services\AnalyticsQueryService;
use App\Services\ClickHouseService;
use App\Services\GeoIpService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClickHouseService::class);
        $this->app->singleton(GeoIpService::class);
        $this->app->singleton(AnalyticsQueryService::class);
    }

    public function boot(): void
    {
        //
    }
}
